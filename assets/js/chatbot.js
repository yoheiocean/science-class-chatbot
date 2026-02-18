(function () {
  function el(id) {
    return document.getElementById(id);
  }

  function appendMessage(logEl, role, text) {
    var row = document.createElement('div');
    row.className = 'scsb-msg scsb-' + role;
    row.textContent = text;
    logEl.appendChild(row);
    logEl.scrollTop = logEl.scrollHeight;
  }

  function historyKey(userId, lessonSlug) {
    return 'scsb_history_' + userId + '_' + lessonSlug;
  }

  function loadHistory(userId, lessonSlug) {
    try {
      var raw = localStorage.getItem(historyKey(userId, lessonSlug));
      var parsed = raw ? JSON.parse(raw) : [];
      return Array.isArray(parsed) ? parsed : [];
    } catch (e) {
      return [];
    }
  }

  function saveHistory(userId, lessonSlug, history) {
    localStorage.setItem(historyKey(userId, lessonSlug), JSON.stringify(history.slice(-12)));
  }

  function init() {
    if (!window.SCSBConfig || !window.SCSBConfig.isLoggedIn) {
      return;
    }

    var user = window.SCSBConfig.currentUser || {};
    var userId = user.id || 0;
    var subjectSelect = el('scsb-subject-select');
    var lessonSelect = el('scsb-lesson-select');
    var logEl = el('scsb-chat-log');
    var inputEl = el('scsb-chat-input');
    var sendBtn = el('scsb-send-btn');
    var resultEl = el('scsb-result');
    var statePhotoEl = el('scsb-state-photo');
    var leaderboardContainer = el('scsb-leaderboard-container');
    var leaderboardSubjectSelect = el('scsb-leaderboard-subject');
    var stateImages = window.SCSBConfig.stateImages || {};

    if (!userId || !subjectSelect || !lessonSelect || !logEl || !inputEl || !sendBtn || !resultEl) {
      return;
    }

    function setPhotoState(stateName) {
      if (!statePhotoEl) {
        return;
      }
      var src = stateImages[stateName] || '';
      if (!src) {
        statePhotoEl.style.display = 'none';
        statePhotoEl.removeAttribute('src');
        return;
      }
      statePhotoEl.src = src;
      statePhotoEl.style.display = '';
    }

    function refreshLeaderboard() {
      if (!leaderboardContainer) {
        return;
      }

      var limit = leaderboardContainer.getAttribute('data-limit') || '10';
      var subject = leaderboardSubjectSelect ? leaderboardSubjectSelect.value : '';
      var body = new URLSearchParams();
      body.append('action', 'scsb_get_leaderboard');
      body.append('nonce', window.SCSBConfig.nonce);
      body.append('limit', limit);
      body.append('subject', subject);
      body.append('_ts', String(Date.now()));

      fetch(window.SCSBConfig.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (data && data.success && data.data && data.data.html) {
            leaderboardContainer.innerHTML = data.data.html;
          }
        })
        .catch(function () {});
    }

    var rawLessons = (window.SCSBConfig && window.SCSBConfig.lessons) || [];
    var lessons = rawLessons.map(function (lesson) {
      var title = lesson.lesson_name || lesson.title || '';
      var slug = lesson.slug || title.toLowerCase().replace(/\s+/g, '-');
      return {
        id: lesson.id || slug,
        subject: lesson.subject || 'General Science',
        title: title,
        slug: slug
      };
    }).filter(function (lesson) {
      return !!lesson.id && !!lesson.title;
    });

    if (!lessons.length) {
      var subjectOption = document.createElement('option');
      subjectOption.value = '';
      subjectOption.textContent = 'No subjects configured';
      subjectSelect.appendChild(subjectOption);
      subjectSelect.disabled = true;

      var option = document.createElement('option');
      option.value = '';
      option.textContent = 'No lessons configured';
      lessonSelect.appendChild(option);
      lessonSelect.disabled = true;
    } else {
      var subjectSet = {};
      lessons.forEach(function (lesson) {
        subjectSet[lesson.subject] = true;
      });
      var subjectNames = Object.keys(subjectSet).sort();
      subjectNames.forEach(function (subjectName) {
        var subjectOption = document.createElement('option');
        subjectOption.value = subjectName;
        subjectOption.textContent = subjectName;
        subjectSelect.appendChild(subjectOption);
      });

      if (leaderboardSubjectSelect) {
        subjectNames.forEach(function (subjectName) {
          var lbOption = document.createElement('option');
          lbOption.value = subjectName;
          lbOption.textContent = subjectName;
          leaderboardSubjectSelect.appendChild(lbOption);
        });
      }
    }

    function populateLessonsForSubject(subjectName) {
      lessonSelect.innerHTML = '';
      lessons.filter(function (lesson) {
        return lesson.subject === subjectName;
      }).forEach(function (lesson) {
        var option = document.createElement('option');
        option.value = lesson.id;
        option.textContent = lesson.title;
        option.setAttribute('data-slug', lesson.slug);
        lessonSelect.appendChild(option);
      });
    }

    function renderHistory() {
      var lessonSlug = lessonSelect.value;
      logEl.innerHTML = '';
      if (!lessonSlug) {
        return;
      }
      var history = loadHistory(userId, lessonSlug);
      history.forEach(function (item) {
        if (item && item.role && item.content) {
          appendMessage(logEl, item.role, item.content);
        }
      });
    }

    subjectSelect.addEventListener('change', function () {
      populateLessonsForSubject(subjectSelect.value);
      renderHistory();
    });
    if (leaderboardSubjectSelect) {
      leaderboardSubjectSelect.addEventListener('change', refreshLeaderboard);
    }
    lessonSelect.addEventListener('change', renderHistory);
    if (!subjectSelect.disabled && subjectSelect.value) {
      populateLessonsForSubject(subjectSelect.value);
    }
    renderHistory();
    setPhotoState('idle');
    refreshLeaderboard();

    sendBtn.addEventListener('click', function () {
      var text = inputEl.value.trim();
      var lessonSlug = lessonSelect.value;

      if (!text || !lessonSlug) {
        return;
      }

      appendMessage(logEl, 'student', text);
      inputEl.value = '';
      resultEl.textContent = 'Thinking...';
      sendBtn.disabled = true;
      setPhotoState('thinking');

      var history = loadHistory(userId, lessonSlug);

      var body = new URLSearchParams();
      body.append('action', 'scsb_send_message');
      body.append('nonce', window.SCSBConfig.nonce);
      body.append('message', text);
      body.append('lessonId', lessonSlug);
      var selectedOption = lessonSelect.options[lessonSelect.selectedIndex];
      body.append('lessonSlug', selectedOption ? (selectedOption.getAttribute('data-slug') || '') : '');
      body.append('history', JSON.stringify(history.slice(-10)));

      fetch(window.SCSBConfig.ajaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          if (!data || !data.success) {
            var errorText = (data && data.data && data.data.error) ? data.data.error : 'Request failed.';
            var statusText = (data && data.data && data.data.status) ? ' (HTTP ' + data.data.status + ')' : '';
            var providerMessage = (data && data.data && data.data.providerMessage) ? ' ' + data.data.providerMessage : '';
            appendMessage(logEl, 'bot', 'Sorry, I hit an error: ' + errorText);
            resultEl.textContent = 'Error' + statusText + ':' + providerMessage;
            setPhotoState('keepTrying');
            return;
          }

          var d = data.data;
          var botReply = d.reply || 'No reply returned.';
          appendMessage(logEl, 'bot', botReply);
          history.push({ role: 'student', content: text });
          history.push({ role: 'bot', content: botReply });
          saveHistory(userId, lessonSlug, history);
          refreshLeaderboard();

          if (d.objectiveMet) {
            var coinPart = d.coinsAwarded > 0 ? ' | +' + d.coinsAwarded + ' Yohei Coin' : ' | Objective already completed';
            resultEl.innerHTML = '<span class=\"scsb-result-label\">Objective met</span><br>Token: ' + d.token + coinPart + ' | Balance: ' + d.coinBalance;
            setPhotoState('objectiveMet');
          } else if (d.tasks && d.tasks.length) {
            resultEl.innerHTML = '<span class=\"scsb-result-label\">Tasks assigned</span><br>' + d.tasks.join(' | ') + ' | Balance: ' + d.coinBalance;
            setPhotoState('keepTrying');
          } else {
            resultEl.innerHTML = '<span class=\"scsb-result-label\">Keep trying</span><br>Keep going. Answer the next question. | Balance: ' + d.coinBalance;
            setPhotoState('keepTrying');
          }
        })
        .catch(function () {
          appendMessage(logEl, 'bot', 'Network error. Please try again.');
          resultEl.textContent = 'Network error.';
          setPhotoState('keepTrying');
        })
        .finally(function () {
          sendBtn.disabled = false;
        });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
