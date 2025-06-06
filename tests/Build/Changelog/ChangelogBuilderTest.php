<?php
namespace Aws\Test\Build\Changelog;

use Aws\Build\Changelog\ChangelogBuilder;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * @covers Aws\Build\Changelog\ChangelogBuilder
 */
class ChangelogBuilderTest extends TestCase
{
    private static $resourceDir = 'tests/Build/Changelog/resources/';
    private static $invalidResourceDir = 'tests/Build/Changelog/resources/invalid/';

    public function testBuildChangelogNoDirectory()
    {
        $this->expectException(\InvalidArgumentException::class);
        $params = [];
        $params['base_dir'] = 'wrong-folder';
        $obj = new ChangelogBuilder($params);
        $obj->buildChangelog();
    }

    public function testBuildChangelogNoReleaseNotes()
    {
        $this->expectException(\InvalidArgumentException::class);
        $tempDir = sys_get_temp_dir() . "/";
        if (!file_exists($tempDir . "/.changes/nextrelease/")) {
            mkdir($tempDir . "/.changes/nextrelease/", 0777, true);
        }
        $params = [];
        $params['base_dir'] = $tempDir;
        $params['release_notes_output_dir'] = '';
        $obj = new ChangelogBuilder($params);
        $obj->buildChangelog();
        unlink($tempDir . "/.changes/nextrelease/");
    }

    public function testBuildChangelogInvalidChangelog()
    {
        $this->expectException(\InvalidArgumentException::class);
        $params = [];
        $params['base_dir'] = self::$resourceDir;
        $params['release_notes_output_dir'] = sys_get_temp_dir() . "/";
        $obj = new ChangelogBuilder($params);
        $obj->buildChangelog();
    }

    public function testBuildChangelogInvalidPathChangelog()
    {
        if (PHP_VERSION_ID >= 80300) {
            $this->expectException(\PHPUnit\Framework\Error\Deprecated::class);
        } else {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('Invalid tag value');
        }

        $params = [];
        $params['base_dir'] = self::$invalidResourceDir;
        $params['release_notes_output_dir'] = sys_get_temp_dir() . "/";
        $obj = new ChangelogBuilder($params);
        $obj->buildChangelog();
    }

    public function testBuildChangelogValid()
    {
        $tempDir = sys_get_temp_dir() . "/";
        file_put_contents($tempDir . "CHANGELOG.md", "# CHANGELOG \n\n\n\n ");
        $params = [];
        $params['base_dir'] = self::$resourceDir;
        $params['release_notes_output_dir'] = $tempDir;
        $obj = new ChangelogBuilder($params);
        $obj->buildChangelog();
        $this->assertTrue($obj->isNewService());
        $lines = file($tempDir . 'CHANGELOG.md');
        $this->assertSame("## next release\n", $lines[2]);
        $this->assertSame("* `Aws\Ec2` - Added Support to Tag Instance\n", $lines[4]);
        $this->assertSame("* `Aws\Ecs` - Test string placeholder for new docs\n", $lines[5]);
        $this->assertSame("* `Aws\s3` - Test string placeholder for new service\n", $lines[6]);
        $this->assertSame("* `Aws\util` - Parse ini files containing comments using #\n", $lines[7]);
        unlink($tempDir . '/CHANGELOG.md');
        $expected = file_get_contents(self::$resourceDir . "/release-json-valid");
        $output = file_get_contents($tempDir . "/.changes/3.22.0");
        $this->assertEquals(
            json_decode($expected, true),
            json_decode($output, true)
        );
        // Verify last character newline matches expected value
        $this->assertSame(substr($expected, -1), substr($output, -1));
        unlink($tempDir . "/.changes/3.22.0");
    }

    public function testCleanNextReleaseFolderValid()
    {
        $tempDir = sys_get_temp_dir() . "/";
        if (!file_exists($tempDir . ".changes/nextrelease/")) {
            mkdir($tempDir . ".changes/nextrelease/", 0777, true);
        }
        $params = [];
        $params['base_dir'] = $tempDir;
        $obj = new ChangelogBuilder($params);
        touch($tempDir . ".changes/nextrelease/temp.json");
        $this->assertCount(1, preg_grep('/^([^.])/', scandir($tempDir . ".changes/nextrelease/")));
        $obj->cleanNextReleaseFolder();
        $this->assertCount(0, preg_grep('/^([^.])/', scandir($tempDir . ".changes/nextrelease/")));
    }
}
