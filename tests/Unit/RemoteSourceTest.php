<?php

namespace Whilesmart\Skillpack\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Whilesmart\Skillpack\Exceptions\AccessDeniedException;
use Whilesmart\Skillpack\Exceptions\DigestMismatchException;
use Whilesmart\Skillpack\Exceptions\SkillpackException;
use Whilesmart\Skillpack\Loader;
use Whilesmart\Skillpack\LoadOptions;
use Whilesmart\Skillpack\Pack;
use Whilesmart\Skillpack\Skill;
use Whilesmart\Skillpack\Sources\RemoteSource;
use Whilesmart\Skillpack\Tests\Support\Archives;
use Whilesmart\Skillpack\Tests\Support\FakeResolver;
use Whilesmart\Skillpack\Tests\Support\FakeTransport;
use Whilesmart\Skillpack\Tests\TestCase;

class RemoteSourceTest extends TestCase
{
    private const SITE = 'https://skills.test';

    private function options(FakeTransport $transport, array $overrides = []): LoadOptions
    {
        return new LoadOptions(...array_merge([
            'allowedHosts' => ['skills.test'],
            'transport' => $transport,
            'resolver' => new FakeResolver(['internal.test' => ['10.0.0.5'], 'mixed.test' => ['203.0.113.10', '127.0.0.1']]),
        ], $overrides));
    }

    private static function digest(string $data): string
    {
        return 'sha256:'.hash('sha256', $data);
    }

    /**
     * @param  list<array<string, string>>  $skills
     */
    private static function index(?string $schema, array $skills): string
    {
        $document = ['skills' => $skills];
        if ($schema !== null) {
            $document['$schema'] = $schema;
        }

        return (string) json_encode($document, JSON_UNESCAPED_SLASHES);
    }

    private function assertFailure(callable $load, string $expected, string $label = ''): void
    {
        try {
            $load();
            $this->fail("{$label}: loaded");
        } catch (SkillpackException $exception) {
            $this->assertStringContainsString($expected, $exception->getMessage(), $label);
        }
    }

    #[Test]
    public function secure_sources_cannot_downgrade_to_plain_http(): void
    {
        $transport = (new FakeTransport)->redirect(self::SITE.'/SKILL.md', str_replace('https://', 'http://', self::SITE).'/SKILL.md');
        $this->expectException(AccessDeniedException::class);
        Loader::load(self::SITE.'/SKILL.md', $this->options($transport));
    }

    #[Test]
    public function remote_access_needs_explicit_allowed_hosts(): void
    {
        $transport = (new FakeTransport)->serve(self::SITE.'/SKILL.md', 'text/markdown', self::REVIEW_SKILL);

        $this->expectException(AccessDeniedException::class);
        Loader::load(self::SITE.'/SKILL.md', new LoadOptions(transport: $transport));
    }

    #[Test]
    public function an_index_loads_each_skill_it_lists_after_checking_its_digest(): void
    {
        $archive = Archives::tarGz(['SKILL.md' => self::LAYOUT_SKILL, 'references/grids.md' => "# Grids\n"]);
        $transport = (new FakeTransport)
            ->serve(self::SITE.'/.well-known/agent-skills/code-review/SKILL.md', 'text/markdown', self::REVIEW_SKILL)
            ->serve(self::SITE.'/cdn/page-layout.tar.gz', 'application/gzip', $archive)
            ->serve(self::SITE.RemoteSource::WELL_KNOWN_INDEX, 'application/json', self::index(RemoteSource::DISCOVERY_SCHEMA, [
                ['name' => 'code-review', 'type' => 'skill-md', 'description' => 'Review code for bugs.', 'url' => 'code-review/SKILL.md', 'digest' => self::digest(self::REVIEW_SKILL)],
                ['name' => 'page-layout', 'type' => 'archive', 'description' => 'Lay out a page.', 'url' => '/cdn/page-layout.tar.gz', 'digest' => self::digest($archive)],
                ['name' => 'later', 'type' => 'plugin', 'description' => 'A future type.', 'url' => 'later.bin', 'digest' => self::digest('')],
            ]));

        $pack = Loader::load(self::SITE, $this->options($transport));

        $this->assertSame([
            ['name' => 'code-review', 'description' => 'Review code for bugs.'],
            ['name' => 'page-layout', 'description' => 'Lay out a page.'],
        ], $pack->index());
        foreach ($pack->all() as $skill) {
            $this->assertSame([], $skill->validate(), $skill->name());
        }
        $layout = $pack->get('page-layout');
        $this->assertSame(self::digest($archive), $layout->digest());
        $this->assertSame(self::SITE.'/cdn/page-layout.tar.gz', $layout->root());
        $this->assertSame("# Grids\n", $layout->reference('grids'));
        $this->assertCount(1, $pack->warnings());
        $this->assertStringContainsString('skipped type "plugin"', $pack->warnings()[0]);

        $this->assertSame(self::SITE.RemoteSource::WELL_KNOWN_INDEX, $transport->requests[0]->url);
        foreach ($transport->requests as $request) {
            $this->assertSame('203.0.113.10', $request->address);
            $this->assertSame('skills.test', $request->host);
            $this->assertSame(443, $request->port);
        }
    }

    #[Test]
    public function an_index_is_refused_when_it_cannot_be_trusted(): void
    {
        $entry = fn (array $change = []) => array_merge([
            'name' => 'code-review', 'type' => 'skill-md', 'description' => 'Review code for bugs.', 'url' => '/SKILL.md', 'digest' => self::digest(self::REVIEW_SKILL),
        ], $change);

        $cases = [
            'v0.1.0, no schema' => [self::index(null, [$entry()]), 'is a v0.1.0 discovery index'],
            'unknown schema' => [self::index('https://schemas.agentskills.io/discovery/9.0.0/schema.json', [$entry()]), 'uses discovery schema'],
            'no skills list' => ['{"$schema":"'.RemoteSource::DISCOVERY_SCHEMA.'"}', 'has no skills list'],
            'digest mismatch' => [self::index(RemoteSource::DISCOVERY_SCHEMA, [$entry(['digest' => self::digest('other')])]), 'not the sha256:'],
            'malformed digest' => [self::index(RemoteSource::DISCOVERY_SCHEMA, [$entry(['digest' => 'sha256:ABC'])]), 'digest must be sha256:'],
            'invalid name' => [self::index(RemoteSource::DISCOVERY_SCHEMA, [$entry(['name' => 'Code_Review'])]), 'name must be'],
            'missing description' => [self::index(RemoteSource::DISCOVERY_SCHEMA, [$entry(['description' => ''])]), 'description must be'],
            'non-web url' => [self::index(RemoteSource::DISCOVERY_SCHEMA, [$entry(['url' => 'file:///etc/passwd'])]), 'not an http or https address'],
            'other host' => [self::index(RemoteSource::DISCOVERY_SCHEMA, [$entry(['url' => 'http://localhost:1/SKILL.md'])]), 'localhost is not an allowed host'],
            'not JSON' => ['{nope', 'is not a discovery index'],
            'non-text field' => ['{"$schema":"'.RemoteSource::DISCOVERY_SCHEMA.'","skills":[{"name":3}]}', 'is not a discovery index'],
        ];

        foreach ($cases as $name => [$index, $expected]) {
            $transport = (new FakeTransport)
                ->serve(self::SITE.'/index.json', 'application/json', $index)
                ->serve(self::SITE.'/SKILL.md', 'text/markdown', self::REVIEW_SKILL);
            $this->assertFailure(fn () => Loader::load(self::SITE.'/index.json', $this->options($transport)), $expected, $name);
        }
    }

    #[Test]
    public function a_digest_mismatch_keeps_its_exception_type(): void
    {
        $transport = (new FakeTransport)
            ->serve(self::SITE.'/index.json', 'application/json', self::index(RemoteSource::DISCOVERY_SCHEMA, [
                ['name' => 'code-review', 'type' => 'skill-md', 'description' => 'Review code for bugs.', 'url' => '/SKILL.md', 'digest' => self::digest('tampered')],
            ]))
            ->serve(self::SITE.'/SKILL.md', 'text/markdown', self::REVIEW_SKILL);

        $this->expectException(DigestMismatchException::class);
        Loader::load(self::SITE.'/index.json', $this->options($transport));
    }

    #[Test]
    public function an_index_description_that_differs_is_a_warning(): void
    {
        $transport = (new FakeTransport)
            ->serve(self::SITE.'/index.json', 'application/json', self::index(RemoteSource::DISCOVERY_SCHEMA, [
                ['name' => 'code-review', 'type' => 'skill-md', 'description' => 'Something else.', 'url' => '/SKILL.md', 'digest' => self::digest(self::REVIEW_SKILL)],
            ]))
            ->serve(self::SITE.'/SKILL.md', 'text/markdown', self::REVIEW_SKILL);

        $skill = Loader::load(self::SITE.'/index.json', $this->options($transport))->get('code-review');

        $this->assertSame(['index-description'], $this->codes($skill->validate()));
        $this->assertTrue($skill->valid());
    }

    #[Test]
    public function an_index_name_must_match_the_skill(): void
    {
        $transport = (new FakeTransport)
            ->serve(self::SITE.'/index.json', 'application/json', self::index(RemoteSource::DISCOVERY_SCHEMA, [
                ['name' => 'reviewer', 'type' => 'skill-md', 'description' => 'Review code for bugs.', 'url' => '/SKILL.md', 'digest' => self::digest(self::REVIEW_SKILL)],
            ]))
            ->serve(self::SITE.'/SKILL.md', 'text/markdown', self::REVIEW_SKILL);

        $pack = Loader::load(self::SITE.'/index.json', $this->options($transport));

        $this->assertSame(['name-folder'], $this->codes($pack->problems()['code-review']));
    }

    #[Test]
    public function a_link_is_read_by_what_it_returns(): void
    {
        $plainIndex = self::index(RemoteSource::DISCOVERY_SCHEMA, [
            ['name' => 'code-review', 'type' => 'skill-md', 'description' => 'Review code for bugs.', 'url' => 'code-review/SKILL.md', 'digest' => self::digest(self::REVIEW_SKILL)],
        ]);
        $transport = (new FakeTransport)
            ->serve(self::SITE.'/skills/code-review/SKILL.md', 'text/plain; charset=utf-8', self::REVIEW_SKILL)
            ->serve(self::SITE.'/skills/index.json', 'text/plain; charset=utf-8', $plainIndex)
            ->serve(self::SITE.'/download', 'application/octet-stream', self::REVIEW_SKILL)
            ->redirect(self::SITE.'/moved', '/skills/code-review/SKILL.md');

        foreach ([
            '/skills/code-review/SKILL.md' => 'code-review',
            '/skills/index.json' => 'code-review',
            '/moved' => 'code-review',
            '/download' => 'download',
        ] as $location => $folder) {
            $skill = Loader::load(self::SITE.$location, $this->options($transport))->get('code-review');

            $this->assertSame($folder, $skill->folder(), $location);
            $this->assertSame("\n# Code review\n", $skill->body, $location);
        }
    }

    #[Test]
    public function redirects_must_stay_on_allowed_hosts(): void
    {
        $transport = (new FakeTransport)
            ->redirect(self::SITE.'/hop', 'https://other.test/SKILL.md')
            ->serve('https://other.test/SKILL.md', 'text/markdown', self::REVIEW_SKILL);

        $this->assertFailure(fn () => Loader::load(self::SITE.'/hop', $this->options($transport)), 'which is not an allowed host');
        $this->assertCount(1, $transport->requests);
    }

    #[Test]
    public function redirect_loops_stop(): void
    {
        $transport = (new FakeTransport)->redirect(self::SITE.'/loop', '/loop');

        $this->assertFailure(fn () => Loader::load(self::SITE.'/loop', $this->options($transport)), 'stopped after 10 redirects');
    }

    #[Test]
    public function private_addresses_are_refused_unless_allowed(): void
    {
        $transport = (new FakeTransport)
            ->serve('https://internal.test/SKILL.md', 'text/markdown', self::REVIEW_SKILL)
            ->serve('https://mixed.test/SKILL.md', 'text/markdown', self::REVIEW_SKILL)
            ->serve('http://127.0.0.1/SKILL.md', 'text/markdown', self::REVIEW_SKILL);
        $hosts = ['allowedHosts' => ['internal.test', 'mixed.test', '127.0.0.1']];

        foreach (['https://internal.test/SKILL.md', 'https://mixed.test/SKILL.md', 'http://127.0.0.1/SKILL.md'] as $location) {
            $this->assertFailure(fn () => Loader::load($location, $this->options($transport, $hosts)), 'not a public address', $location);
        }
        $this->assertSame([], $transport->requests);

        $pack = Loader::load('https://internal.test/SKILL.md', $this->options($transport, $hosts + ['allowPrivateAddresses' => true]));
        $this->assertSame('code-review', $pack->get('code-review')->name());
        $this->assertSame('10.0.0.5', $transport->requests[0]->address);
    }

    #[Test]
    public function a_github_style_zip_is_read_inside_its_top_folder(): void
    {
        $zip = Archives::zip([
            'repo-main/skills/page-layout/SKILL.md' => self::LAYOUT_SKILL,
            'repo-main/skills/page-layout/references/grids.md' => "# Grids\n",
            'repo-main/README.md' => 'readme',
        ]);
        $transport = (new FakeTransport)->serve(self::SITE.'/archive/main.zip', '', $zip);

        $pack = Loader::load(self::SITE.'/archive/main.zip', $this->options($transport, ['directory' => 'skills']));
        $skill = $pack->get('page-layout');

        $this->assertTrue($skill->valid());
        $this->assertCount(1, $skill->references());
        $this->assertSame(self::SITE.'/archive/main.zip!/repo-main/skills/page-layout', $skill->root());
        $this->assertCount(0, Loader::load(self::SITE.'/archive/main.zip', $this->options($transport)));
    }

    #[Test]
    public function a_single_skill_archive_is_named_by_its_file(): void
    {
        $transport = (new FakeTransport)->serve(self::SITE.'/page-layout.tar.gz', '', Archives::tarGz([
            'SKILL.md' => self::LAYOUT_SKILL,
            'references/grids.md' => "# Grids\n",
        ]));

        $skill = Loader::load(self::SITE.'/page-layout.tar.gz', $this->options($transport))->get('page-layout');

        $this->assertTrue($skill->valid());
        $this->assertSame('page-layout', $skill->folder());
    }

    #[Test]
    public function stored_zip_entries_are_read_and_checksums_verified(): void
    {
        $zip = Archives::zip(['SKILL.md' => self::LAYOUT_SKILL, 'references/grids.md' => "# Grids\n"], [], false);
        $transport = (new FakeTransport)
            ->serve(self::SITE.'/page-layout.zip', 'application/zip', $zip)
            ->serve(self::SITE.'/broken.zip', 'application/zip', str_replace('# Grids', '# Grads', $zip));

        $this->assertTrue(Loader::load(self::SITE.'/page-layout.zip', $this->options($transport))->get('page-layout')->valid());
        $this->assertFailure(fn () => Loader::load(self::SITE.'/broken.zip', $this->options($transport)), 'fails its checksum');
    }

    #[Test]
    public function remote_loading_refuses_what_it_cannot_trust(): void
    {
        $big = Archives::zip(['repo/page-layout/SKILL.md' => self::LAYOUT_SKILL.str_repeat('x', 4096)], [], false);
        $transport = (new FakeTransport)
            ->serve(self::SITE.'/big.zip', '', $big)
            ->serve(self::SITE.'/page.html', '', '<html>');

        $cases = [
            'download too large' => [self::SITE.'/big.zip', ['maxDownloadBytes' => 100], 'larger than 100 bytes'],
            'unpacks too large' => [self::SITE.'/big.zip', ['maxExtractedBytes' => 100], 'unpacks to more than 100 bytes'],
            'too many entries' => [self::SITE.'/two.zip', ['maxFiles' => 1], 'holds more than 1 entries'],
            'not a skill' => [self::SITE.'/page.html', [], 'is not a discovery index, a SKILL.md or a .zip or .tar.gz archive'],
            'missing' => [self::SITE.'/gone.zip', [], '404'],
            'folder missing' => [self::SITE.'/big.zip', ['directory' => 'elsewhere'], 'is not a folder'],
        ];
        $transport->serve(self::SITE.'/two.zip', '', Archives::zip(['SKILL.md' => self::LAYOUT_SKILL, 'references/grids.md' => "# Grids\n"]));
        foreach ($cases as $name => [$location, $overrides, $expected]) {
            $this->assertFailure(fn () => Loader::load($location, $this->options($transport, $overrides)), $expected, $name);
        }
        $this->assertCount(1, Loader::load(self::SITE.'/big.zip', $this->options($transport)));

        $this->assertFailure(fn () => Loader::load('file:///etc/passwd', $this->options($transport)), 'not an http or https address');
        $this->assertFailure(fn () => (new RemoteSource('file:///etc/passwd', $this->options($transport)))->load(), 'not an http or https address');
        $this->assertFailure(fn () => Loader::load('https://user:secret@skills.test/SKILL.md', $this->options($transport)), 'carries credentials');
    }

    #[Test]
    public function archives_with_unsafe_entries_are_refused(): void
    {
        $zipLink = Archives::zip(['SKILL.md' => self::LAYOUT_SKILL], ['references/grids.md' => '../../secret']);
        $cases = [
            'tar symlink out' => [Archives::tarGz(['SKILL.md' => self::LAYOUT_SKILL], ['references/grids.md' => '../../secret']), 'application/gzip', 'outside the archive'],
            'tar absolute link' => [Archives::tarGz(['SKILL.md' => self::LAYOUT_SKILL], ['references/grids.md' => '/etc/passwd']), 'application/gzip', 'outside the archive'],
            'tar hard link out' => [Archives::tarGz(['SKILL.md' => self::LAYOUT_SKILL], [], ['references/grids.md' => '../secret']), 'application/gzip', 'outside the archive'],
            'tar traversal' => [Archives::tarGz(['SKILL.md' => self::LAYOUT_SKILL, '../escape.md' => 'x']), 'application/gzip', 'unsafe path'],
            'zip symlink out' => [$zipLink, 'application/zip', 'outside the archive'],
            'zip traversal' => [Archives::zip(['SKILL.md' => self::LAYOUT_SKILL, '../escape.md' => 'x']), 'application/zip', 'unsafe path'],
            'zip backslash' => [Archives::zip(['SKILL.md' => self::LAYOUT_SKILL, 'references\\..\\..\\x' => 'x']), 'application/zip', 'unsafe path'],
            'duplicate entry' => [Archives::tarGz(['SKILL.md' => self::LAYOUT_SKILL, './SKILL.md' => self::REVIEW_SKILL]), 'application/gzip', 'twice'],
        ];

        foreach ($cases as $name => [$archive, $contentType, $expected]) {
            $transport = (new FakeTransport)
                ->serve(self::SITE.'/index.json', 'application/json', self::index(RemoteSource::DISCOVERY_SCHEMA, [
                    ['name' => 'page-layout', 'type' => 'archive', 'description' => 'Lay out a page.', 'url' => '/skill', 'digest' => self::digest($archive)],
                ]))
                ->serve(self::SITE.'/skill', $contentType, $archive)
                ->serve(self::SITE.'/direct.bytes', $contentType, $archive);
            foreach (['/index.json', '/direct.bytes'] as $location) {
                $this->assertFailure(fn () => Loader::load(self::SITE.$location, $this->options($transport)), $expected, "{$name} via {$location}");
            }
        }

        $inside = Archives::tarGz(['SKILL.md' => self::LAYOUT_SKILL, 'references/grids.md' => "# Grids\n"], ['references/copy.md' => 'grids.md']);
        $transport = (new FakeTransport)->serve(self::SITE.'/page-layout.tar.gz', 'application/gzip', $inside);
        $skill = Loader::load(self::SITE.'/page-layout.tar.gz', $this->options($transport))->get('page-layout');

        $this->assertSame(['grids.md'], $skill->references());
    }

    #[Test]
    public function an_index_archive_must_hold_skill_md_at_its_root(): void
    {
        $wrapped = Archives::tarGz(['page-layout/SKILL.md' => self::LAYOUT_SKILL]);
        $transport = (new FakeTransport)
            ->serve(self::SITE.'/index.json', 'application/json', self::index(RemoteSource::DISCOVERY_SCHEMA, [
                ['name' => 'page-layout', 'type' => 'archive', 'description' => 'Lay out a page.', 'url' => '/page-layout.tar.gz', 'digest' => self::digest($wrapped)],
            ]))
            ->serve(self::SITE.'/page-layout.tar.gz', 'application/gzip', $wrapped);

        $this->assertFailure(fn () => Loader::load(self::SITE.'/index.json', $this->options($transport)), 'has no SKILL.md');
    }

    #[Test]
    public function an_index_listing_too_many_skills_is_refused(): void
    {
        $entries = array_fill(0, 3, ['name' => 'a', 'type' => 'skill-md', 'description' => 'x', 'url' => '/a', 'digest' => self::digest('')]);
        $transport = (new FakeTransport)->serve(self::SITE.'/index.json', 'application/json', self::index(RemoteSource::DISCOVERY_SCHEMA, $entries));

        $this->assertFailure(fn () => Loader::load(self::SITE.'/index.json', $this->options($transport, ['maxSkills' => 2])), 'more than 2 skills');
        $this->assertCount(1, $transport->requests);
    }

    #[Test]
    public function remote_skills_carry_their_provenance_for_storage(): void
    {
        $transport = (new FakeTransport)->serve(self::SITE.'/skills/code-review/SKILL.md', 'text/markdown', self::REVIEW_SKILL);
        $skill = Loader::load(self::SITE.'/skills/code-review/SKILL.md', $this->options($transport))->get('code-review');

        $copy = (new Pack([new Skill($skill->files, $skill->provenance)]))->get('code-review');

        $this->assertSame('skill-md', $skill->provenance['type']);
        $this->assertSame($skill->contentHash(), $copy->contentHash());
        $this->assertSame($skill->folder(), $copy->folder());
        $this->assertSame(json_decode((string) json_encode($skill->provenance), true), $skill->provenance);
    }
}
