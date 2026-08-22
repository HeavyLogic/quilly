# 🪶 Quilly

It's not a CMS. It's not WYSIWYG.

It's a simple HTML content editor, based on your browser's native `contenteditable`.

### 💡 Why build this?

* **Simple inline editing** — There are a lot of flat-file CMS systems out there, but only a couple offers full page inline editing. And they are already overengenered for simple static sites. Not the way I like it.
* **No DB, no MD, no {{templates}}** — Where do you store data after editing? Astro-based static editors are very popular, but it looks like this: there's your HTML-files, and there's your markdown-files with data. And then you have to compile your HTML, or you have to hook your Astro CMS with repo on Github and setup CI/CD. I got rid of all extra steps and layers. Your data is stored directly in your HTML and your changes are available immediately. Static means static.
* **Powered by PHP 8.4** — in PHP 8.4 the legacy **libxml2** parser was replaced with **Lexbor** — a modern, spec-compliant C library that parses according to the HTML5 living standard. Another reason why I chose PHP over some JS stack - easier to deploy. You can upload this on any cheap hosting and you're done.
* **0 dependencies** — I keep the implementation simple, there's no heavy JS bundles, only PHP and Vanilla JS. So it won't interfere with the site's CSS or JS.
* **K.I.S.S.** — The most important thing, that I tried to achieve here: UI for editing should be simple and intuitive for client (editor). But I also tried my best to make it easier for maintainers (myself included). I designed it around the idea that less steps = better.


### 🎯 Who Is This For?

I'm a freelancer. And in my experience so far, there's only one group of clients who's okay with AI-generated sites: the "I want to test a business idea" crowd. Deploying a CMS-based site for them is already too much effort. Then you have to maintain it, keep it updated — it just isn't worth it for really basic AI-generated sites.

This is where this editor comes in. Now I can slap editing capabilities to any static site in no time. It's pretty basic and limited, but for AI-generated sites, I feel like, this is exactly the way it should be.


### ✨ Features

- Full page inline editing with formatting buttons.
- You can let the editor change only the right things and protect the layout from breaking.
- Built-in image uploader with WebP conversion.
- Generates thumbnails for your images and adds them to `srcset` automatically.
- ZIP-based revisions that include all editable images from your HTML.
- SQLite-based authentication and "superuser" script for creating editors.

### 🚀 How to use it

#### 1. Upload the `admin` directory to the root of the static HTML website.

#### 2. Add the script before your `</body>` closing tag:

```html
<script src="/admin/assets/userbar.js" type="module"></script>
```

By default, editor resolves HTML file path from the current URL. But if your Nginx has some weird rules, you can specify path of your current file like this:

```html
<script src="/admin/assets/userbar.js" type="module" data-filepath="/index.html"></script>
```

#### 3. Make your content editable

Mark text and images as editable with the `editable` class and a unique `id`. You should place `editable` on low-level elements where the actual text begins. For lists, add the `editable` class to `ul` and `ol`, not `li`.

```html
<h1 class="editable" id="hero-title">Welcome to <span>Our Website</span></h1>
<p class="editable" id="hero-text">This content <b>can be edited</b> directly on the page.</p>

<img
  class="editable"
  id="hero-image"
  src="/assets/static/hero.jpg"
  alt="Hero Image"
>

<ul class="editable" id="hearo-ul">
  <li>Editor can edit lists</li>
  <li>it will be fine</li>
</ul>
```

The image uploader creates thumbnails to use in `srcset` with `size="auto"`. `srcset` doesn't consider `height`. So if you use `object-fit: cover` on your images with specific height, then the thumbnail can stretch and become blurry. To prevent this, add `data-height` attribute to your images. This will tell the uploader to skip generating thumbnail sizes that don't satisfy the element's minimum height from your design.

```html
<img
  class="editable"
  id="hero-image"
  src="/assets/static/hero.jpg"
  data-height="350"
  alt="Hero Image"
>
```

### 🔐 Security heads up

By default, your SQLite database will be stored in `/restricted/` folder. It's used for authentication. f you're running Apache, the PHP script will try to protect it automatically with `.htaccess` file. But if you're only running Nginx, you should add this to your site configuration, inside the `server` section:

```
location ^~ /restricted/ {
    deny all;
}
```



## 🤖 AI prompt (for preparing your HTML)

Copy & paste the prompt below into your AI tool to automatically adapt your HTML file for this editor

```text
Format my HTML file so it can be edited by a low-level static HTML editor. Follow these rules for adding `class="editable"` and unique `id="..."` attributes:

1. GRANULARITY (LEAF NODES): Place `class="editable"` and a unique `id` ONLY on leaf elements that contain direct raw text (e.g., <h1 class="editable" id="h1-1">, <p class="editable" id="p-1">, <a class="editable" id="btn-1">, <span class="editable" id="s-1">). Never wrap large multi-element container divs in `editable`.
2. NO NESTING: `editable` elements must NEVER be nested inside another `editable` element.
3. LISTS (ul / ol): Always place `class="editable"` and an `id` directly on the <ul> or <ol> tag (e.g., <ul class="editable" id="list-1">). Do NOT place `editable` on individual <li> items, as the editor dynamically manages adding/removing <li> items.
4. LIST ICONS: If <li> items contain icons (e.g., SVGs, font icons), move those icons into CSS using `::before` pseudo-elements. The <li> items should contain raw text only so newly added list items automatically inherit the icon via CSS. Ask the user for the CSS if you encounter this, but the user gave you only HTML code with no inline CSS.
5. SAFE INLINE TAGS: Inline tags like <b>, <strong>, <i>, <u>, <s>, <strike>, <a>, and <span> are allowed inside an `editable` element.
6. COMPLEX STRUCTURES: If a text block is mixed with non-text elements (like badges, absolute-positioned decorative shapes, or icons), isolate the editable text into a dedicated child <span class="editable" id="...">Text</span> to prevent the client from breaking the layout.
7. STYLING/CLASSES: If you see some styling classes on inline tags (examples: `<h2>Welcome to <span class="accent">our awesome</span> site</h2>`, `<p>This content <strong class="any-class">should be editable</strong>`), you should remove the class from the inline element, create a new class on the parent, and then update the CSS selector to `.editable.your-class > span`. Ask the user for the CSS if you encounter this, but the user gave you only HTML code with no inline CSS.
```

### 🔧 Requirements

- **PHP 8.4+** — required for `Dom\HTMLDocument` and Lexbor.
- **ZipArchive** (`ext-zip`) — required for page revisions.
- **Imagick** or **GD** — required for image conversion and thumbnail generation.
- **SQLite3** — required for authentication.

### 📝 License

Distributed under the MIT License.
