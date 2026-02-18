# Science Class Sidebar Chatbot (WordPress Plugin)

A sidebar AI tutor widget for a high school science class website.

## What It Does

- Login-only student chatbot in a sidebar widget
- Teacher personality + LLM settings in wp-admin
- Lesson management in wp-admin (Subject, Lesson, Objectives)
- Objective checking + task assignment
- Token generation when objective is met
- Yohei Coin system with balances per student
- Overall and subject-specific leaderboards
- Student progress logging + bulk delete tools
- Student coin management (manual add/remove by subject)
- Optional state images (Idle, Thinking, Objective Met, Keep Trying)

## Project Structure

- `science-class-chatbot.php` - main plugin logic
- `includes/class-science-chatbot-widget.php` - widget markup
- `assets/js/chatbot.js` - frontend chat + leaderboard behavior
- `assets/css/chatbot.css` - widget styles

## Requirements

- WordPress site with admin access
- LLM API endpoint + API key + model
- Students must have WordPress user accounts and be logged in

## Install

1. Copy `science-class-chatbot` folder into `wp-content/plugins/`
2. Activate plugin in **Plugins**
3. Go to **Science Chatbot** in wp-admin and configure settings
4. Add **Science Class Chatbot** widget to your sidebar in **Appearance > Widgets**

## Admin Pages

- **Science Chatbot**: personality, LLM config, state images
- **Lessons**: create/edit/delete subject+lesson+objectives
- **Student Progress**: view logs, bulk delete rows
- **Student Coins**: manual add/remove coins and clear earned records by subject/all

## Leaderboards

- Widget includes a leaderboard with a subject filter
- Shortcode is available:
  - `[scsb_leaderboard]`
  - `[scsb_leaderboard limit="20"]`
  - `[scsb_leaderboard limit="20" subject="Biology"]`

## Notes

- Manual coin changes now require selecting a subject and are tracked as subject-linked records
- Subject leaderboard totals come from earned records (objective completions + manual subject adjustments)
- Updating plugin files in place preserves settings and stored data

## Quick Update Workflow

1. Replace plugin files in `wp-content/plugins/science-class-chatbot/`
2. Hard refresh browser (`Ctrl+F5`)
3. If needed, deactivate/reactivate plugin once

---

## Publish This Project to GitHub (GitHub Desktop)

### If repo is not created yet

1. Open **GitHub Desktop**
2. **File > Add local repository...**
3. Choose this folder: `...\science-class-chatbot`
4. If prompted that it is not a repo, click **Create a repository**
5. Name it (example: `science-class-chatbot-wp`)
6. Add a summary and keep this `README.md`
7. Click **Create repository**

### Commit and publish

1. In GitHub Desktop, review changed files
2. Enter commit message (example: `Initial plugin version`)
3. Click **Commit to main**
4. Click **Publish repository**
5. Choose visibility (Public/Private)
6. Click **Publish Repository**

### If repo already exists on GitHub

1. In GitHub Desktop, commit locally
2. Click **Push origin**

### Future updates

1. Edit files locally
2. In GitHub Desktop: commit
3. Click **Push origin**
