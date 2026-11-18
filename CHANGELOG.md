## [1.0.0] - 2026-11-18
- Load Agent Skills from folders on disk, in-memory file bundles, `SKILL.md` URLs, `.zip` and `.tar.gz` archives and Agent Skills Discovery v0.2.0 indexes
- `Loader`, `Pack`, `Skill`, `Problem`, `LoadOptions` and the `SkillSource` contract for custom sources
- Validation separate from loading, with the same problem codes as the Go skillpack
- `list_skills`, `read_skill` and `read_reference` tools with strict JSON Schema arguments
- `checks.yaml` checks, `references/` and `assets/` reads, template rendering and guidance prompts
- Remote access only to explicitly allowed hosts, on every redirect and index entry, with private network addresses refused by default and the checked address pinned for the connection
- Download, unpacked size, entry, skill and file limits; unsafe archive paths and links refused; sha256 digests verified for every index entry
- Stable content hashes over file paths and bytes
