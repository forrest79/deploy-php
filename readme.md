Forrest79/DeployPhp
===================

[![License](https://img.shields.io/badge/License-BSD%203--Clause-blue.svg)](https://github.com/forrest79/DeployPhp/blob/master/license.md)

Simple assets builder and application deploy helper.


Requirements
------------

Forrest79/DeployPhp requires PHP 7.1 or higher and is primarily designed for using with Nette Framework.

- [Nette Framework](https://github.com/nette/nette)


Installation
------------

* Install Forrest79/DeployPhp to your project using [Composer](http://getcomposer.org/). Add this to your composer.json (there is no Composer package yet):

```json
{
    "require": {
        "forrest79/php-deploy": "dev-master"
    },
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/forrest79/DeployPhp.git"
        }
    ]
}
```

And run:

```sh
$ composer update forrest79/php-deploy
```


Documentation
------------

### Assets

This is simple assets builder. Currently supports copying files, compiling and minifying [less](http://lesscss.org/) files and JavaScript files and in debug environment also generating map files.

Using is very simple. Just create new instance `Forrest79\DeployPhp\Assets` class and pass configuraion array to constructor. `key` is directory to process (for ```DeployPhp\Assets::COPY```) or target file (for ```DeployPhp\Assets::JS``` or ```DeployPhp\Assets::LESS```) or directory (for ```DeployPhp\Assets::SASS```) for source data and `value` can be simple `DeployPhp\Assets::COPY` which tells to copy this file/directory from source to destination as is or another `array` with items:

- required `type` - with value `DeployPhp\Assets::COPY` to copy file/directory or `DeployPhp\Assets::LESS` to compile and minify less to CSS or `DeployPhp\Assets::JS` to concatenate and minify JavaScripts
- optional `env` - if missing, this item is proccess for debug and production environment or you can specify concrete environment `DeployPhp\Assets::DEBUG` or `DeployPhp\Assets::PRODUCTION`
- required `file` for `type => DeployPhp\Assets::LESS` - with source file to compile and minify
- required `file` for `type => DeployPhp\Assets::SASS` - with source file to compile and minify
- required `files` for `type => DeployPhp\Assets::JS` - with source files to concatenate and minify

To build assets you need first call `setup($configNeon, $sourceDirectory, $destinationDirectory)` method.

- `$configNeon` neon file where will be stored actual assets hash that you can use in your application
- `$sourceDirectory` directory with source assets files
- `$destinationDirectory` directory where assets will be built

And finally run `buildDebug()` or `buildProduction()` method. First builds assets only if there was some changed file and creates new hash from all files timestamp, the second builds assets everytime and creates hash from every files content.

Neon file with hash has this structure:

```yml
parameters:
    assets:
        hash: c11a678785091b7f1334c24a4123ee75 # md5 hash (32 characters)
```

#### Example

In `deploy/assets.php`:

```php
use Forrest79\DeployPhp;

require __DIR__ . '/vendor/autoload.php';

return (new DeployPhp\Assets([
    'images' => DeployPhp\Assets::COPY,
    'fonts' => DeployPhp\Assets::COPY,
    'css/styles.css' => [ // target file
        'type' => DeployPhp\Assets::LESS,
        'file' => 'css/main.less',
    ],
    'css/styles' => [ // target directory, main.css will be created here
        'type' => DeployPhp\Assets::SASS,
        'file' => 'css/main.sass',
    ],
    'js/scripts.js' => [ // target file
        'type' => DeployPhp\Assets::JS,
        'files' => [
            'js/bootstrap.js',
            'js/modernizr-custom.js',
            'js/web.js',
        ],
    ],
    'js/jquery.min.js' => DeployPhp\Assets::COPY,
    'js/jquery.min.map' => [
        'type' => DeployPhp\Assets::COPY,
        'env' => DeployPhp\Assets::DEBUG,
    ],
], (file_exists($assetsLocalFile = (__DIR__ . '/assets.local.php'))) ? require $assetsLocalFile : []));
```

In `deploy/assets.local.php` you can define local source assets directory, if you're using some virtual server, where the paths are different from your host paths. This directory will be used for JS and CSS map files to property open source files in browser console:

```php
return [
	'localSourceDirectory' => 'P:/app/assets',
];
```

In `app/bootstrap.php`:

```php
$configurator->addConfig(__DIR__ . '/config/config.neon');
$configurator->addConfig($assetsConfigFile = __DIR__ . '/config/config.assets.neon');
$configurator->addConfig(__DIR__ . '/config/config.local.neon');

if ($configurator->isDebugMode()) {
    /** @var Forrest79\DeployPhp\Assets $assets */
    $assets = require __DIR__ . '/../deploy/assets.php';
    $assets
        ->setup($assetsConfigFile, __DIR__ . '/assets', __DIR__ . '/../www/assets')
        ->buildDebug();
}

$container = $configurator->createContainer();
```

In debug mode is hash calculated from every assets files timestamp - creating hash is fast (if you change file or add/remove some file, hash is changed and assets are automatically rebuilt before request is performed).

When building application:

```php
/** @var DeployPhp\Assets $assets */
$assets = require __DIR__ . '/assets.php';
$assets
    ->setup($releaseBuildDirectory . '/app/config/config.assets.neon', $releaseBuildDirectory . '/app/assets', $releaseBuildDirectory . '/www/assets')
    ->buildProduction();
```

Hash is computed from all files content, so hash is changed only when some file content is changed or same file is add/remove (creating hash is slow).


### Build and deploy

Contains just some helper methods to checkout from GIT, copy files via SCP a run commands via SSH. For documentation look at example.

#### Example

```php
use Forrest79\DeployPhp;

require __DIR__ . '/../vendor/autoload.php'; // with required Nette components
require __DIR__ . '/vendor/autoload.php';

class Deploy extends DeployPhp\Deploy
{
    /** @var array */
    protected $config = [
        'vps' => [
            'gitBranch' => 'master',
            'ssh' => [
                'server' => 'ssh.site.com',
                'directory' => '/var/www/site.com',
                'username' => 'forrest79',
                'public_key' => 'C:\\Certificates\\certificate.pub',
                'private_key' => 'C:\\Certificates\\certificate',
                'passphrase' => NULL,
            ],
            'deployScript' => 'https://www.site.com/deploy.php',
        ]
    ];

    /** @var string */
    private $releasesDirectory;

    /** @var string */
    private $releaseName;

    /** @var string */
    private $releasePackage;

    /** @var string */
    private $releaseBuildPackage;


    protected function setup()
    {
        $this->releasesDirectory = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'temp' . DIRECTORY_SEPARATOR . 'deploy';

        $this->releaseName = 'release-' . date('Ymd-His') . '-' . uniqid();
        $this->releasePackage = $this->releaseName . '.tar.gz';
        $this->releaseBuildPackage = $this->releasesDirectory . DIRECTORY_SEPARATOR . $this->releasePackage;
    }


    public function run()
    {
        $this->log('=> Creating build...');
        $this->createBuild();
        $this->log('   ...DONE');

        $this->log('=> Deploying build...');
        $this->deployBuild();
        $this->log('   ...DONE');

        $this->log('=> Cleaning up local files');
        $this->delete($this->releaseDirectory);
        $this->log('   ...DONE');
    }


    private function createBuild()
    {
        $releaseBuildDirectory = $this->releasesDirectory . DIRECTORY_SEPARATOR . $this->releaseName;

        $this->log('     -> checkout from GIT', FALSE);
        if (!$this->gitCheckout(__DIR__ . DIRECTORY_SEPARATOR . '..', $releaseBuildDirectory, $this->environment['gitBranch'])) {
            $this->error(' ...cant\'t checkout from GIT');
        }
        $this->log(' ...OK');

        $this->log('     -> building assets', FALSE);
        /** @var DeployPhp\Assets $assets */
        $assets = require __DIR__ . '/assets.php';
        $assets
            ->setup($releaseBuildDirectory . '/app/config/config.assets.neon', $releaseBuildDirectory . '/app/assets', $releaseBuildDirectory . '/www/assets')
            ->buildProduction();
        $this->log(' ...OK');

        $this->log('     -> preparing package', FALSE);
        $this->delete($releaseBuildDirectory . '/app/assets');
        $this->delete($releaseBuildDirectory . '/conf');
        $this->delete($releaseBuildDirectory . '/data');
        $this->delete($releaseBuildDirectory . '/db');
        $this->delete($releaseBuildDirectory . '/deploy');
        $this->delete($releaseBuildDirectory . '/download');
        $this->delete($releaseBuildDirectory . '/logs');
        $this->delete($releaseBuildDirectory . '/temp');
        $this->delete($releaseBuildDirectory . '/.gitignore');
        $this->delete($releaseBuildDirectory . '/composer.json');
        $this->delete($releaseBuildDirectory . '/composer.lock');
        $this->log(' ...OK');

        $this->log('     -> compresing package', FALSE);
        $this->gzip($this->releasesDirectory, $this->releaseName, $this->releaseBuildPackage);
        $this->log(' ...OK');
    }


    private function deployBuild()
    {
        $remoteReleaseDirectory = $this->environment['ssh']['directory'] . '/releases';
        $remoteReleaseBudilDirectory = $remoteReleaseDirectory . '/' . $this->releaseName;
        $this->log('     -> uploading build package', FALSE);
        if (!$this->scp($this->releaseBuildPackage, $remoteReleaseDirectory)) {
            $this->error(' ...an error occured while uploading build package');
        }
        $this->log(' ...OK');

        $this->log('     -> extracting build package, creating temp, symlinks and removing build package', FALSE);
        if (!$this->ssh('cd ' . $remoteReleaseDirectory . ' && tar xfz ' . $this->releasePackage . ' && rm ' . $this->releasePackage . ' && mkdir ' . $remoteReleaseBudilDirectory . '/temp && ln -s ' . $this->environment['ssh']['directory'] . '/logs ' . $remoteReleaseBudilDirectory . '/logs && ln -s ' . $this->environment['ssh']['directory'] . '/data ' . $remoteReleaseBudilDirectory . '/www/data && ln -s ' . $this->environment['ssh']['directory'] . '/config/config.local.neon ' . $remoteReleaseBudilDirectory . '/app/config/config.local.neon')) {
            $this->error(' ...an error occured while extracting build package, creating temp and symlinks');
        }
        $this->log(' ...OK');

        $this->log('     -> releasing build (replace link to current)', FALSE);
        if (!$this->ssh('ln -sfn ' . $remoteReleaseBudilDirectory . ' ' . $this->environment['ssh']['directory'] . '/current_new && mv -Tf ' . $this->environment['ssh']['directory'] . '/current_new ' . $this->environment['ssh']['directory'] . '/current')) {
            $this->error(' - an error occured while releasing build');
        }
        $this->log(' ...OK');

        $this->log('     -> running after deploy script', FALSE);
        if (!$this->httpRequest($this->environment['deployScript'] . '?' . $this->releaseName , 'OK')) {
            $this->error(' ...an error occured while running deploy script');
        }
        $this->log(' ...OK');

        $keepBuilds = 5;
        $this->log('     -> cleaning up old builds', FALSE);
        if (!$this->ssh('ls ' . $remoteReleaseDirectory . '/* -1td | tail -n +' . ($keepBuilds + 1) . ' | grep -v ' . $this->releaseName . ' | xargs rm -rf')) {
            $this->error(' ...an error occured while cleaning old build');
        }
        $this->log(' ...OK');
    }

}


/**
 * RUN FROM COMMAND LINE *******************************************************
 * *****************************************************************************
 */


if ($argc == 1) {
    echo "Usage: php deploy.php <environment> [git-branch]";
    exit(1);
}

echo 'Enter SSH key password: ';
$additionalOptions = ['ssh' => ['passphrase' => stream_get_line(STDIN, 1024, PHP_EOL)]];

if ($argc > 2) {
    $additionalOptions['gitBranch'] = $argv[2];
}

try {
    (new Deploy($argv[1], $additionalOptions))->run();
} catch (\Exception $e) {
    echo $e->getMessage() . "\n";
    exit(1);
}
```
