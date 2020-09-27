# Forrest79/DeployPhp

[![License](https://img.shields.io/badge/License-BSD%203--Clause-blue.svg)](https://github.com/forrest79/DeployPhp/blob/master/license.md)
[![Build Status](https://travis-ci.org/forrest79/DeployPhp.svg?branch=master)](https://travis-ci.org/forrest79/DeployPhp)

Simple assets builder and application deploy helper for PHP projects.


## Requirements

Forrest79/DeployPhp requires PHP 7.1 or higher.


## Installation

The recommended way to install Forrest79/DeployPhp is through Composer:

```sh
composer require --dev forrest79/deploy-php
```


## Documentation

### Assets

This is simple assets builder. Currently supports copying files, compiling and minifying [less](http://lesscss.org/) files, [sass](https://sass-lang.com/) files and JavaScript files and in debug environment also generating map files.

For compiling and minifying is required `node.js` with installed `npm` packages `less`, `node-sass` or `uglify-js`. In Debian or Ubuntu, you can do it like this:

```bash
curl -sL https://deb.nodesource.com/setup_10.x | sudo -E bash -
sudo apt-get install -y nodejs

# LESS compiler
sudo npm install -g less

# SASS compiler
sudo npm install -g --unsafe-perm node-sass

# JS compiler
sudo npm install -g uglify-js
```

Using is very simple. Examples show how this works with [Nette Framework](https://github.com/nette/nette). Just create new instance `Forrest79\DeployPhp\Assets` class and pass temp directory, assets source directory and configuration array to constructor. `key` is directory to process (for ```DeployPhp\Assets::COPY```) or target file (for ```DeployPhp\Assets::JS``` or ```DeployPhp\Assets::LESS```) or directory (for ```DeployPhp\Assets::SASS```) for source data and `value` can be simple `DeployPhp\Assets::COPY` which tells to copy this file/directory from source to destination as is or another `array` with items:

- required `type` - with value `DeployPhp\Assets::COPY` to copy file/directory or `DeployPhp\Assets::LESS` to compile and minify less to CSS or `DeployPhp\Assets::JS` to concatenate and minify JavaScripts
- optional `env` - if missing, this item is proccess for debug and production environment or you can specify concrete environment `DeployPhp\Assets::DEBUG` or `DeployPhp\Assets::PRODUCTION`
- required `file` for `type => DeployPhp\Assets::LESS` - with source file to compile and minify
- required `file` or `files` for `type => DeployPhp\Assets::SASS` - with source file or files to compile and minify
- required `files` for `type => DeployPhp\Assets::JS` - with source files to concatenate and minify

Next two parameters are callable function, first is for reading hash from file and second is write hash to file. In example is shown, how you can write it to neon and use it with Nette DI.

Last (fourth) parameter is optional and define array with optional settings. More about this is under example.

To build assets you need first call `buildDebug($configNeon, $destinationDirectory)` or `buildProduction($configNeon, $destinationDirectory)` method.

- `$configFile`  file where will be stored actual assets hash that you can use in your application
- `$destinationDirectory` directory where assets will be built

First builds assets only if there was some changed file and creates new hash from all files timestamp (and also create map files), the second builds assets everytime and creates hash from every files content.

#### Example

In `deploy/assets.php`:

```php
use Forrest79\DeployPhp;

require __DIR__ . '/vendor/autoload.php';

return (new DeployPhp\Assets(
    __DIR__ . '/../temp',
    __DIR__ . '/assets',
    [
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
        'css/many-styles' => [ // target directory, main.css and print.css will be created here
            'type' => DeployPhp\Assets::SASS,
            'files' => [
                'css/main.sass',
                'css/print.sass',
            ]
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
    ],
    static function (string $configFile): ?string {
        if (!file_exists($configFile)) {
            return NULL;
        }

        $data = Neon\Neon::decode(file_get_contents($configFile));
        if (!isset($data['assets']['hash'])) {
            return NULL;
        }

        return $data['assets']['hash'];
    },
    static function (string $configFile, string $hash): void {
        file_put_contents($configFile, "assets:\n\t\thash: $hash\n");
    },
    ((($localConfig = @include __DIR__ . '/assets.local.php') === FALSE) ? [] : $localConfig)
);
```

Neon file with hash has this structure:

```yml
parameters:
    assets:
        hash: c11a678785091b7f1334c24a4123ee75 # md5 hash (32 characters)
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

if (PHP_SAPI !== 'cli') {
    $assetsConfigFile = __DIR__ . '/config/config.assets.neon';
    $configurator->addConfig($assetsConfigFile);
    if ($configurator->isDebugMode()) {
        $assets = @include __DIR__ . '/../assets/assets.php'; // intentionally @ - file may not exists - good when production with production assets is running in debug mode (production preferable doesn't have assets source) 
        if ($assets !== FALSE) {
            $assets->buildDebug($assetsConfigFile, __DIR__ . '/../../www/assets');
        }
    }
}

$configurator->addConfig(__DIR__ . '/config/config.local.neon');

$container = $configurator->createContainer();
```

In debug mode is hash calculated from every assets files timestamp - creating hash is fast (if you change file or add/remove some file, hash is changed and assets are automatically rebuilt before request is performed).

In Nette you need to define you own Assets extension, that will read hash from ```assets.hash``` and with some sort of service, you can use it in your application. For example, like this:

```php
// Service to use in application

namespace App\Assets;

class Assets
{
    /** @var string */
    private $hash;


    public function __construct(string $hash)
    {
        $this->hash = $hash;
    }


    public function getHash(): string
    {
        return $this->hash;
    }

}


// Extension that uses neon structure with hash (just register this as extension in config.neon)

namespace App\Assets\DI;

use App\Assets;
use Nette\DI\CompilerExtension;

class Extension extends CompilerExtension
{
    private $defaults = [
        'hash' => NULL,
    ];


    public function loadConfiguration()
    {
        $builder = $this->getContainerBuilder();

        $config = $this->validateConfig($this->defaults, $this->config);

        $builder->addDefinition($this->prefix('assets'))
            ->setFactory(Assets\Assets::class, [$config['hash']]);
    }

}
```

In your application, you can use hash as query parameter ```styles.css?hash``` or as virtual path in web server, example for nginx, load assets at path ```/assets/hash/styles.css```:

```
location ~ ^/assets/ {
    expires 7d;
    rewrite ^/assets/[a-z0-9]+/(.+)$ /assets/$1 break;
}
```

When building application:

```php
/** @var DeployPhp\Assets $assets */
$assets = require __DIR__ . '/assets.php';
$assets->buildProduction($releaseBuildDirectory . '/app/config/config.assets.neon', $releaseBuildDirectory . '/www/assets')
```

Hash is computed from all files content, so hash is changed only when some file content is changed or same file is add/remove (creating hash is slow).


### Build and deploy

Contains just some helper methods to checkout from GIT, copy files via SCP a run commands via SSH. For documentation look at example.

#### Example

```php
use Forrest79\DeployPhp;

require __DIR__ . '/../vendor/autoload.php';

//define('SSH_PRIVATE_KEY', 'define-this-in-deploy.local.php');
//define('DEPLOY_TEMP_DIRECTORY', 'define-this-in-deploy.local.php'); // if you want to change from default repository temp - on VirtualBox is recommended /tmp/... or some local (not shared) directory

require __DIR__ . '/deploy.local.php';

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
        $this->releasesDirectory = defined('DEPLOY_TEMP_DIRECTORY')
            ? DEPLOY_TEMP_DIRECTORY
            : __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'temp' . DIRECTORY_SEPARATOR . 'deploy';

        $this->releaseName = 'release-' . date('Ymd-His') . '-' . uniqid();
        $this->releasePackage = $this->releaseName . '.tar.gz';
        $this->releaseBuildPackage = $this->releasesDirectory . DIRECTORY_SEPARATOR . $this->releasePackage;
    }


    public function run()
    {
        if (!$this->validatePrivateKey()) {
            $this->error('Bad passphrase for private key or bad private key.');
        }

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

try {
    $passphrase = Deploy::getHiddenResponse();
    echo PHP_EOL;
} catch (\RuntimeException $e) {
    echo '[Can\'t get hidden response, password will be visible]: ';
    $passphrase = Deploy::getResponse();
}

$additionalOptions = ['ssh' => ['passphrase' => $passphrase]];

if ($argc > 2) {
    $additionalOptions['gitBranch'] = $argv[2];
}

try {
    (new Deploy($argv[1], $additionalOptions))->run();
} catch (\Exception $e) {
    echo $e->getMessage() . "\n";
    exit(1);
}
