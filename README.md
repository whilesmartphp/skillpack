# whilesmart/skillpack

Load [Agent Skills](https://agentskills.io/specification) in PHP from a folder on disk, an in-memory
bundle of files, a `SKILL.md` URL, a `.zip` or `.tar.gz` URL, or an Agent Skills Discovery v0.2.0
index. Validation is a separate call. A model gets the skills as prompt text or as tools. Loading
a skill never runs anything in it.

The package has no framework dependency. It needs PHP 8.2+, `ext-mbstring`, `ext-zlib` and
`symfony/yaml`; the default HTTP transport for remote skills uses `ext-curl`.

The package is unreleased. Install the implementation branch from GitHub:

```sh
composer config repositories.skillpack vcs https://github.com/whilesmartphp/skillpack
composer require whilesmart/skillpack:dev-feat/initial-package
```

A skill is a folder with a `SKILL.md` (YAML frontmatter with `name` and `description`, then
markdown guidance) and optional `references/`, `assets/`, `scripts/` and `checks.yaml`. This
package ships no skills of its own.

## Where skills come from

```php
use Whilesmart\Skillpack\Loader;
use Whilesmart\Skillpack\LoadOptions;

Loader::load('/opt/team-skills');                                   // a folder on disk
Loader::load('/opt/team-skills', new LoadOptions(allowedRoots: ['/opt/team-skills']));
Loader::fromFiles(['SKILL.md' => $markdown, 'references/a.md' => $text]); // bytes in memory
Loader::load('https://example.com', new LoadOptions(allowedHosts: ['example.com']));
Loader::load('https://github.com/acme/skills/archive/refs/heads/main.zip', new LoadOptions(
    allowedHosts: ['github.com', 'codeload.github.com'],
    directory: 'skills',
));
Loader::load($source);                                              // any Contracts\SkillSource
```

A folder holding `SKILL.md` is one skill; otherwise each folder inside it holding `SKILL.md` is.
`fromFiles()` takes relative path => raw bytes and reads the same way. Files under
`node_modules/` and `__pycache__/` are left out.

### Local folders

When `allowedRoots` is set, the folder and every skill folder it holds (after resolving symbolic
links) must sit inside one of them. With no roots any readable folder may be loaded, so a host
that takes paths from users should always set them. Symbolic links inside a skill are followed
while they stay in the skill folder. A link that leaves it is never read: its path is recorded
in the skill's provenance as `links_outside`, reading it fails and validation reports
`link-outside`.

### Remote addresses

Remote access is denied unless `allowedHosts` names the host. The list applies to the address
given, to each redirect and to every skill an index lists. Each host is resolved before
connecting and refused when any of its addresses is loopback, private, link-local, shared,
multicast or reserved, including IPv4 addresses carried inside IPv6 forms; set
`allowPrivateAddresses: true` to reach internal hosts. The checked address is pinned for the
connection so the name cannot be resolved to something else in between. Redirects (up to 10)
are followed by this package, never by the transport, and URLs with credentials are refused.

A URL is read by what it returns: its `Content-Type`, then its extension, then its first bytes.
`text/plain` and `application/octet-stream` say nothing, so they fall through.

| The URL returns | It is read as |
|---|---|
| JSON | An Agent Skills Discovery index |
| Markdown | One `SKILL.md`; `.../code-review/SKILL.md` and `.../code-review.md` are both in folder `code-review` |
| A `.zip` or `.tar.gz` | The skills inside it; with a single top-level folder, `directory` is read inside that folder |
| Anything else | An error naming the address and its `Content-Type` |

A bare site address such as `https://example.com` reads
`https://example.com/.well-known/agent-skills/index.json`.

Everything is read in memory and nothing is written to disk. The defaults are 32 MiB per
download, 128 MiB unpacked and 5000 entries per archive or folder, 500 skills and a 60 second
timeout per request. `LoadOptions` changes them; every value is checked and read-only once built.
`transport` (a `Contracts\HttpTransport`) and `resolver` (a `Contracts\HostResolver`) replace the
curl transport and DNS lookups.

### Agent Skills Discovery

| The RFC says | This package |
|---|---|
| Check `$schema`; do not process an unknown one | Accepts only `https://schemas.agentskills.io/discovery/0.2.0/schema.json` |
| Treat an index without `$schema` as v0.1.0 | Refuses it, because v0.1.0 has no digests |
| Resolve `url` against the index URL | Resolves relative, path-absolute and absolute references, then accepts only http and https on an allowed host |
| Verify each artifact's sha256 `digest` | One mismatch fails the whole load; `Skill::digest()` keeps the verified digest |
| Skip an entry with an unknown `type`, and warn | Skips it and adds a line to `Pack::warnings()` |
| `name` follows the naming rules; `description` is at most 1024 characters | Fails the load when an entry breaks either rule |
| `description` should match the `SKILL.md` frontmatter | `validate()` reports `index-description` as a warning |
| `SKILL.md` sits at the archive root | Required for archives listed in an index |
| Reject `..`, absolute paths and links leaving the skill | Refuses the archive; links that stay inside are left out |
| Do not run files under `scripts/` | Runs nothing |

## Skills, packs and validation

```php
$pack = Loader::load('skills');
$skill = $pack->get('page-layout');    // throws SkillNotFoundException listing the names it has

$skill->name();
$skill->description();
$skill->frontmatter;                   // parsed YAML mapping
$skill->body;                          // markdown after the frontmatter
$skill->files;                         // relative path => raw bytes, including SKILL.md
$skill->provenance;                    // where it came from, JSON-safe

$skill->validate();                    // list<Problem>
$skill->valid();                       // false when any problem is an error
$skill->contentHash();                 // "sha256:..." over sorted paths and bytes
```

`Problem` has readonly `code`, `message`, `severity` (`error` or `warning`) and `path` (nullable),
and `toArray()`. Validation checks the name's format and length and that it matches its folder,
the description and compatibility lengths, that metadata maps text to text, that `allowed-tools`
is a string, that the frontmatter has no fields outside the spec, that the folder holds no second
`SKILL.md` (outside `evals/` and dependency folders), and that every local Markdown link in
`SKILL.md` exists inside the skill. Frontmatter that is not valid YAML but reads as plain
`key: value` lines still loads, and validation reports `frontmatter-yaml`.

`Pack` rejects two skills with one name and offers `all()`, `get()`, `has()`, `index()` (name and
description), `indexText()`, `problems()`, `warnings()` and `tools()`.

### Storing and rebuilding skills

`files` holds raw bytes, which may be binary; encode them (for example with base64) before
putting them in JSON. Provenance holds only JSON values. A stored skill is rebuilt exactly with
`new Skill($files, $provenance)` or `Loader::fromFiles($files, $provenance)`; the content hash
depends only on the files. The provenance keys `Skill` reads are `folder`, `root`, `digest`,
`index_description` and `links_outside`; other keys are kept as given.

## Giving skills to a model

```php
$skill->reference('grids');                              // references/grids or references/grids.md
$skill->asset('logo.png');
$skill->render('section', ['title' => 'Home']);          // assets/section.md with {{title}} filled
$skill->checks();                                        // [['id' => ..., 'description' => ...], ...]

foreach ($pack->tools() as $tool) {
    register($tool->name, $tool->description, $tool->parametersJson());
}
$result = $pack->tool('read_skill')->call($modelArgumentsJson);
```

`tools()` returns `list_skills`, `read_skill` and `read_reference` with the same names,
descriptions and JSON Schemas as the Go skillpack. `call()` takes the model's JSON (or a decoded
array) and throws `ToolArgumentException` for anything that is not an object of declared text
arguments.

`Guidance::build($skills, $task, $alreadyUsed, $namesOnly)` builds a prompt that offers skills
as craft rather than steps, lists each skill's checks and asks the model to record a departure
(check id, items, reason) when it goes against one on purpose.

## Custom sources

Implement `Contracts\SkillSource` to load skills from anywhere, such as a database:

```php
final class StoredSource implements SkillSource
{
    public function load(): Pack { /* authorize, then build Skills from stored files */ }
}
```

A source decides its own authorization and limits; `Loader::load($source)` calls `load()` and
nothing else. `Sources\LocalSource`, `Sources\RemoteSource` and `Sources\FilesSource` are the
built-in sources.

## Not in this version

- Checks are listed, not run. Running checks, workflows and evals is up to the host.
- Nothing in `scripts/` is executed and no tool access is granted by `allowed-tools`.
- Publishing a discovery index and installing skills to disk are not provided.
- Zip64 archives, encrypted zip entries and compression other than stored or deflate are refused.
- Nothing is cached; every remote load fetches again. Compare `Skill::digest()` or
  `contentHash()` between loads to detect changes.

## Exceptions

Every exception extends `Exceptions\SkillpackException`: `SkillNotFoundException`,
`UnsafePathException`, `LimitExceededException`, `AccessDeniedException`,
`DigestMismatchException`, `ToolArgumentException` and `InvalidOptionsException`.

## License

MIT

For local sources, `maxFiles` bounds filesystem entries, including directories, skipped entries, and symbolic links. Direct archives also enforce `maxSkills`. Tool argument strings must encode a JSON object; native PHP `null` still means no arguments.
