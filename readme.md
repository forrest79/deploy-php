# Forrest79/DeployPhp

[![Latest Stable Version](https://poser.pugx.org/forrest79/deploy-php/v)](//packagist.org/packages/forrest79/deploy-php)
[![Monthly Downloads](https://poser.pugx.org/forrest79/deploy-php/d/monthly)](//packagist.org/packages/forrest79/deploy-php)
[![License](https://poser.pugx.org/forrest79/deploy-php/license)](//packagist.org/packages/forrest79/deploy-php)
[![Build](https://github.com/forrest79/deploy-php/actions/workflows/build.yml/badge.svg?branch=master)](https://github.com/forrest79/deploy-php/actions/workflows/build.yml)

Simple assets builder and application deploy helper for PHP projects.


## Requirements

Forrest79/DeployPhp requires PHP 8.0 or higher.


## Installation

The recommended way to install Forrest79/DeployPhp is through Composer:

```sh
composer require --dev forrest79/deploy-php
```


## Documentation

### Assets

This is a simple assets builder. Currently, it supports copying files, compiling and minifying [less](http://lesscss.org/) files, [sass](https://sass-lang.com/) files and JavaScript (simple minifier [UglifyJS](https://github.com/mishoo/UglifyJS) or complex [rollup.js](https://rollupjs.org/) + recommended [Babel](https://babeljs.io/)) files and in debug environment also generating map files.

For compiling and minifying is required `node.js` with installed `npm` packages `less`, `node-sass`, `uglify-js` or `rollup` (`babel`) environment. In Debian or Ubuntu, you can do it like this (`-g` option install package globally in the system, not in your repository):

```bash
curl -sL https://deb.nodesource.com/setup_15.x | sudo -E bash -
sudo apt-get install -y nodejs

# LESS compiler
npm install less
#sudo npm install -g less

# SASS compiler
npm install node-sass
#sudo npm install -g node-sass

# UglifyJS compiler
npm install uglify-js
#sudo npm install -g uglify-js

# Babel and Rollup (prefer not to install this globally)
npm install rollup @rollup/plugin-node-resolve @rollup/plugin-commonjs rollup-plugin-terser @rollup/plugin-babel @babel/core @babel/preset-env @babel/plugin-transform-runtime core-js
```

Using is straightforward. Examples show how this works with [Nette Framework](https://github.com/nette/nette). Just create new instance `Forrest79\DeployPhp\Assets` class and pass temp directory, assets source directory and configuration array to constructor. `key` is a directory to process (for ```DeployPhp\Assets::COPY```) or target file (for `DeployPhp\Assets::UGLIFYJS`, `DeployPhp\Assets::ROLLUP` or `DeployPhp\Assets::LESS`) or directory (for `DeployPhp\Assets::SASS`) for source data and `value` can be simple `DeployPhp\Assets::COPY` which tells to copy this file/directory from source to destination or another `array` with items:

- required `type` - with value `DeployPhp\Assets::COPY` to copy file/directory or `DeployPhp\Assets::LESS` to compile and minify less to CSS or `DeployPhp\Assets::UGLIFYJS` to concatenate and minify JavaScripts or `DeployPhp\Assets::ROLLUP` to use modern JavaScript environment
- optional `env` - if missing, this item is processed for debug and production environment, or you can specify concrete environment `DeployPhp\Assets::DEBUG` or `DeployPhp\Assets::PRODUCTION`
- required `file` for `type => DeployPhp\Assets::LESS` - with source file to compile and minify
- required `file` or `files` for `type => DeployPhp\Assets::SASS` - with source file or files to compile and minify
- required `files` for `type => DeployPhp\Assets::UGLIFYJS` - with source files to concatenate and minify
- required `file` for `type => DeployPhp\Assets::ROLLUP` - with source file to process (example configuration is below)

The next two parameters are callable function, the first is for reading hash from file, and the second is to write hash to file. In example is shown, how you can write it to neon and use it with Nette DI.

Last (fourth) parameter is optional and define an array with optional settings. More about this is under the example.

To build assets you need first call `buildDebug($configNeon, $destinationDirectory)` or `buildProduction($configNeon, $destinationDirectory)` method.

- `$configFile`  file where will be stored actual assets hash that you can use in your application
- `$destinationDirectory` directory where assets will be built

First builds assets only if there was some changed file and creates new hash from all files timestamps (and also create map files), the second builds assets every time and creates hash from every file content.

#### rollup.js environment with Babel

This is modern JavaScript building configuration. You must prepare `rollup` configuration file in your assets directory:

Create files `assets\rollup.config.js`:

```js
import { babel } from '@rollup/plugin-babel';
import commonjs from '@rollup/plugin-commonjs';
import { nodeResolve } from '@rollup/plugin-node-resolve';
import { terser } from 'rollup-plugin-terser';

const config = {
	input: process.env.INPUT_FILE, // source file from PHP settings
	output: [
		{ // this compile file for old browsers
			file: process.env.OUTPUT_FILE.replace('{format}', 'iife'), // output file from PHP settings - string {format} is replaced with iife
			format: 'iife',
			name: 'app',  // you can change this, it's some your identificator
			sourcemap: !!parseInt(process.env.SOURCE_MAP, 10), // this provide source map for DEVEL and not for production
		},
		{ // this complie modules JS for modern browsers
			file: process.env.OUTPUT_FILE.replace('{format}', 'esm'),
			format: 'esm',
			sourcemap: !!parseInt(process.env.SOURCE_MAP, 10),
		}
	],
	plugins: [
		nodeResolve(), // with this, you can import from node_modules
		commonjs(), // this resolve require() function
		babel({ // babel settings
			babelHelpers: 'runtime',
			presets: [
				[
					'@babel/preset-env',
					{
						'bugfixes': true,
						'corejs': '3.9',
						'targets': '>0.25%',
						'useBuiltIns': 'usage',
					}
				]
			],
			plugins: ['@babel/plugin-transform-runtime'],
			exclude: /\/node_modules\/core-js\//, // we must exclude core-js from being transpiled
		}),
		terser(), // minification
	]
};

export default config;
```

In your HTML, you can use both files like this:

```html
<script type="text/javascript" src="/js/scripts.iife.js" nomodule defer></script>
<script type="module" src="/js/scripts.esm.js"></script>
```

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
		'js/scripts.{format}.js' => [ // target file - will be compiled for more formats
			'type' => DeployPhp\Assets::ROLLUP,
			'file' => 'js/index.js',
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

In `deploy/assets.local.php` you can define local source assets directory, if you're using some virtual server, where the paths are different from your host paths. This directory will be used for JS and CSS map files to property open source files in the browser console:

```php
return [
	'localSourceDirectory' => 'P:/app/assets',
];
```

Or you need to specify here your local server bin directory, if differ from `/usr/bin:/bin` (directory, where is `node` binary):

```php
return [
	'systemBinPath' => '/opt/usr/bin:/opt/bin',
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

In debug mode, hash is calculated from every assets file timestamp - creating hash is fast (if you change file or add/remove some file, hash is changed and assets are automatically rebuilt before the request is performed).

In Nette, you need to define you own Assets extension, that will read hash from ```assets.hash``` and with some sort of service, you can use it in your application. For example, like this:

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
location /assets/ {
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

Hash is computed from all file content, so hash is changed only when some file content is changed or the same file is added/remove (creating hash is slow).


### Build and deploy

Contains just some helper methods to checkout from GIT, copy files via SFTP, and run commands via SSH. For documentation look at example.

#### Example

```php
use Forrest79\DeployPhp;

require __DIR__ . '/../vendor/autoload.php';

//define('SSH_PRIVATE_KEY', 'define-this-in-deploy.local.php');
//define('SSH_AGENT_SOCK', 'define-this-in-deploy.local.php');
//define('DEPLOY_TEMP_DIRECTORY', 'define-this-in-deploy.local.php'); // if you want to change from default repository temp - on VirtualBox is recommended /tmp/... or some local (not shared) directory

require __DIR__ . '/deploy.local.php';

class Deploy extends DeployPhp\Deploy
{
    /** @var array<string, array<string, bool|float|int|string|array<mixed>|NULL>> */
    protected array $config = [
        'vps' => [
            'gitBranch' => 'master',
            'ssh' => [
                'server' => 'ssh.site.com',
                'directory' => '/var/www/site.com',
                'username' => 'forrest79',
                'private_key' => 'C:\\Certificates\\certificate',
                'passphrase' => NULL, // is completed dynamically - if needed (agent is tried at first), can be also callback call when password is needed
				'ssh_agent' => SSH_AGENT_SOCK, // TRUE - try to read from env variable, string - socket file
            ],
            'deployScript' => 'https://www.site.com/deploy.php',
        ]
    ];

    private string $releasesDirectory;

    private string $releaseName;

    private string $releasePackage;

    private string $releaseBuildPackage;


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
        /** when password is get at the begin of the script (the old way)
        if (!$this->validatePrivateKey()) {
            $this->error('Bad passphrase for private key or bad private key.');
        }
        */

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

        $assets = require __DIR__ . '/assets.php';
        assert($assets instanceof DeployPhp\Assets);

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
        if (!$this->sftpPut($this->releaseBuildPackage, $remoteReleaseDirectory)) {
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

/** when password is get at the begin of the script 
echo 'Enter SSH key password: ';

try {
    $passphrase = Deploy::getHiddenResponse();
    echo PHP_EOL;
} catch (\RuntimeException $e) {
    echo '[Can\'t get hidden response, password will be visible]: ';
    $passphrase = Deploy::getResponse();
}

$additionalOptions = ['ssh' => ['passphrase' => $passphrase]];
*/

$additionalOptions = [
	'ssh' => [
		'passphrase' => function (Deploy $deploy, string $privateKeyFile): string {
			$passphrase = NULL;

			do {
				echo $passphrase === NULL ? PHP_EOL . '          > Enter SSH key password: ' : '  > Bad password, enter again: ';

				try {
					$passphrase = Deploy::getHiddenResponse();
					echo PHP_EOL . '        ';
				} catch (\RuntimeException) {
					echo '[Can\'t get hidden response, password will be visible]: ';
					$passphrase = Deploy::getResponse();
				}
			} while (!$deploy->validatePrivateKey($privateKeyFile, $passphrase));

			return $passphrase;
		},
	],
];

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

### Composer monorepo

IF you're using monorepo for you applications, you need simple tool to prepare correct `composer.lock`. This is the simple one for a repository that meets these requirements:
- one shared global vendor directory with all libraries
- more applications with local vendors that on local development using the shared one and are installed on production

> Be careful, using this tool is always performed update on the global composer! The next step is copy global composer to the local one and update is also performed here. After this is local vendor cleaned.

> Just for hint, differences between global and locals composer.json are shown. This may not be a mistake.

#### Example:

```
/apps/appA/composer.json
/apps/appA/composer.lock
/apps/appA/vendor (autoload.php -> /vendor/autoload.php)
/apps/appB/composer.json
/apps/appB/composer.lock
/apps/appB/vendor (autoload.php -> /vendor/autoload.php)
/vendor/autoload.php
/vendor/[with all packages]
composer.json
composer.lock
prepare-monocomposer (source is below)
``` 
- global vendor is committed in repository and to prepare production build, global vendor is copied to the local one and `composer install` is executed in the app directory, so only needed packages are kept here

```php
#!/usr/bin/env php
<?php declare(strict_types=1);

(new Forrest79\DeployPhp\ComposerMonorepo(__DIR__ . '/composer.json', '--ignore-platform-reqs'))->updateSynchronize([
	'appA' => __DIR__ . '/apps/appA/composer.json',
	'appB' => __DIR__ . '/apps/appB/composer.json',
]);
```

> Second parameter to `ComposeMonorepo` constructor is optional parameters to `composer update` command.
