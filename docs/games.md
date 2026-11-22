---
title: "Games and applications"
order: 31
---

# Games and applications

A piece of software has a **kind** (`Software::$kind`, `SoftwareKind`):
`package` (Composer, Unity), `game` or `app`. A game's or an application's
releases carry **builds**, one per platform (`Platform`: `webgl`, `windows`,
`macos`, `linux`, `android`), made elsewhere - Unity, a CI - and attached to
the release:

```bash
bin/console forge:artifact:attach <slug> <version> <platform> <file> [--publish] [--changelog "..."]
```

or from the back office (Releases > Builds). The release is made when it
does not exist yet: a game need not come from a repository's tags. A build
replaces the platform's previous one; its sha256 is taken on the way in.

| Platform | File |
|---|---|
| webgl | a zip of the build folder (`index.html`, `Build/`, `TemplateData/`), at its root or under one folder |
| windows | a zip of the build folder, an `.exe` or an `.msi` |
| linux | a zip of the build folder, a tarball (`.tar.gz`, `.tgz`, `.tar.xz`), an `.AppImage` or a `.deb` |
| macos | a zip of the `.app`, a `.dmg` or a `.pkg` |
| android | an `.apk` or an `.aab` |

A zip's `docs/` folder (at its root or under its one top folder) becomes the
version's documentation ([below](#each-versions-documentation)).

## The publish API

A CI or an editor publishes a build in one request, opened by
`forge.publish_token` sent as a bearer token:

```bash
curl -fsS -H "Authorization: Bearer $FORGE_PUBLISH_TOKEN" \
     -F platform=windows -F published=1 -F "changelog=<CHANGELOG-entry.md" \
     -F file=@Build/MyGame-Windows.zip [-F docs=@docs.zip] \
     https://example.org/api/software/my-game/releases/1.2.0
```

The software must exist (the back office). The release is made when it is
new - published at once unless `published` is `0` -, its changelog set when
`changelog` (or `notes`) is sent, and the platform's previous build
replaced. It answers `201` (a new release) or `200`, with the version as the
JSON API reads it and the build's `sha256` to compare with what was sent:

| Error | Status |
|---|---|
| `publishing_closed` | 503: no `forge.publish_token` |
| `bad_token` | 401 |
| `unknown_software`, `bad_version`, `bad_platform` | 404, 400, 400 |
| `no_file`, `bad_upload`, `too_large` | 400, 400, 413 (`forge.max_build_size`, PHP's upload limits) |
| `refused` (+ `reason`) | 400: a file the platform does not take |

## The versions as JSON

A game or an application asks whether a newer version exists:

- `GET /api/software`: every published software, with its latest version;
- `GET /api/software/{slug}`: its latest version and every published one,
  highest version first.

A version reads `{software, version, published, changelog, changes, files,
docs}`: `changes` is the changelog as a list (one line each, a leading `- `
taken off), `files` one entry per platform - `url` (the download, `forge_download`),
`filename`, `size`, `sha256`, `uploaded` -, `docs` the address of its
documentation or null. The latest is the highest version number published,
whatever the order they came out in. In a link, `latest` stands for it:
`/telechargements/my-game/latest?format=windows` downloads the newest Windows
build.

## Each version's documentation

Kept beside the builds (`forge.storage_dir/<slug>/<version>/docs/`), it comes
from a build's `docs/` folder, from the repository's `docs/` at the tag (with
git/git-bundle), or from the back office (Releases > Builds: the home page
written in Markdown, or a zip or `.md` uploaded) - and from the publish API's
`docs`. It is read at `/telechargements/{slug}/{version}/docs/{page}`, its
pages beside, the other versions that have theirs listed; the software's page
links each version's. omnidocs/markdown renders it when installed (callouts,
code frames, headings), league/commonmark else; without either it is shown as
the Markdown it is. Pictures (png, jpg, gif, svg, webp) are served from the
same folder.

## Free and paid games

Without omnipack/marketplace every game is free: what follows needs it.

- A **free** game is played in the browser (its WebGL build, in its page) and
  downloaded freely, its builds per platform.
- A **paid** game is downloaded once bought (a licence offer, as any licensed
  software), its builds per platform through signed links. It is played in
  the browser only when the studio opens a **playable preview** of it
  (`Software::$playablePreview`, off by default; "Playable preview" in the
  back office) - then by anyone, as a demo.

## The game's page

The software page (`/telechargements/<slug>`) of a game shows, above its
versions:

- **Play**, when its newest version has a WebGL build and the game plays in
  the browser (free, or a paid game with its playable preview open). Nothing of the game loads
  before Play is pressed: `bundles/forge/js/play.js` then frames
  `/play/<slug>/<version>/` in the page (without JavaScript the link opens it
  on its own), with a full-screen button. A game that stores nothing before
  it is played leaves the site's cookie panel untouched; build it with
  **Data Caching** off (Player Settings > Publishing Settings) so that Unity
  writes nothing to the browser's IndexedDB either.
- Its trailer (an uploaded video) and its screenshots (uploads, in their
  order), both set in the back office.
- Under each version, its changelog and its builds: a signed, short-lived
  link each (`/telechargements/<slug>/<version>?format=<platform>`), its size
  and its sha256.

## WebGL builds: compression

`/play/<slug>/<version>/<path>` (PlayController, WebglBuilds) unpacks the
build once, beside its zip in the storage, and serves each file with what
Unity's loader needs:

- `*.br` with `Content-Encoding: br`, `*.gz` with `Content-Encoding: gzip`;
- the type of what they hold: `application/wasm` for the `.wasm` (the browser
  then compiles it as it streams in), `application/javascript` for the
  `.js`, `application/octet-stream` for the `.data`.

So the browser decompresses the build, not Unity's JavaScript: build with
Brotli (Unity's default; Brotli needs HTTPS, which the site is) and leave
**Decompression Fallback** off - it would make the loader bigger and
slower for nothing. The headers are set by PHP, so they do not depend on the
web server's configuration. A build made with the fallback (`.unityweb`
files) is served too, as it is.

The frame is the stage's size (16:9, the page's width): build with a WebGL
template whose canvas fills its window (`width: 100%; height: 100%`,
`matchWebGLToCanvasSize: true`) - Unity's Default template draws a fixed
960x600 canvas, cropped in the frame on a phone. The sample game's
`Assets/WebGLTemplates/Forge/index.html` is one: a canvas, a loading bar,
nothing else.

A paid game without a playable preview is not played here (403; the staff
may, to check a build), its files then cached by the browser alone
(`Cache-Control: private`).

## Checking a licence from the game

`com.glitchr.licensing`, a Unity package served by the forge's own registry
(see [upm.md](upm.md)), calls the licence API (see
[licensing.md](licensing.md)) and checks the signed token offline.
