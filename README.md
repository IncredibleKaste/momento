# Momento mockup preview

A standalone, interactive design preview with sample content. No installation or build step is needed.

## Open the preview

1. [Download the ZIP](https://github.com/IncredibleKaste/momento/archive/refs/heads/mockup-preview.zip) and extract it.
2. Open `index.html` in your browser. It opens the welcome page.
3. Use **Sign in** for the login preview, or **Explore the preview** to open the feed.

You can also open `instant-memories-v2.html` directly for the feed, profiles, chat, search, saved photos, and settings. Switch between light and dark themes using the theme controls.

If your browser restricts local files, serve the extracted folder with Python:

```sh
python3 -m http.server 8000 --bind 127.0.0.1
```

Then open <http://127.0.0.1:8000>.

To clone just this preview:

```sh
git clone --single-branch --branch mockup-preview https://github.com/IncredibleKaste/momento.git momento-preview
```

## What to expect

- Welcome and login screens, a photo feed, profiles, follows, likes, comments, saved photos, search, settings, and chat demonstrate the proposed interface.
- All interactions use sample data in the current page session; changes reset on reload.
- Photo selection previews a local file. It does not upload it.
- Login, external identity providers, messages, and reports are demonstrations; credentials are not sent, messages are not delivered, and there is no backend.
- Privacy and Impressum pages are placeholders.
- Sample images and icons are included. Google Fonts requires internet access; system fonts are used when unavailable.

This branch contains only the static mockup. The application source is on the repository's other branches.
