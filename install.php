<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 0);
set_time_limit(0);

if (extension_loaded('xdebug')) {
    ini_set('xdebug.max_nesting_level', 100000);
}

$installer = new Installer();

$isCli = php_sapi_name() === 'cli';

if ($isCli) {
    $errors = $installer->checkServer();
    if (!empty($errors)) {
        echo strip_tags($errors) . PHP_EOL;
        exit(1);
    }

    if ($argc <= 1 || in_array('--help', $argv) || in_array('-h', $argv)) {
        echo "Evolution CMS Installer" . PHP_EOL;
        echo "Usage: php install.php [options]" . PHP_EOL;
        echo "Options:" . PHP_EOL;
        echo "  --list               List available versions (branches and releases)" . PHP_EOL;
        echo "  --install=VERSION    Install specific version (e.g., --install=\"tags/3.1.0\" or --install=\"heads/master\")" . PHP_EOL;
        echo "  --help, -h           Show this help message" . PHP_EOL;
        exit(0);
    }

    if (in_array('--list', $argv)) {
        echo "Fetching available versions..." . PHP_EOL;

        $branches = $installer->getBranches();
        $releases = $installer->getReleases();

        echo PHP_EOL . "Available releases:" . PHP_EOL;
        foreach ($releases as $release) {
            echo "  tags/{$release['tag']} - {$release['name']}" . PHP_EOL;
        }

        echo PHP_EOL . "Available branches:" . PHP_EOL;
        foreach ($branches as $branch) {
            echo "  heads/{$branch['tag']} - {$branch['name']}" . PHP_EOL;
        }

        exit(0);
    }

    $installVersion = null;
    foreach ($argv as $arg) {
        if (strpos($arg, '--install=') === 0) {
            $installVersion = substr($arg, 10);
            break;
        }
    }

    if ($installVersion) {
        echo "Installing Evolution CMS version: $installVersion" . PHP_EOL;
        if ($installer->getArchive($installVersion)) {
            echo "Installation completed successfully!" . PHP_EOL;
            echo "Please navigate to the install directory to complete the setup." . PHP_EOL;
            exit(0);
        } else {
            echo "Installation failed. Please check if the specified version exists." . PHP_EOL;
            exit(1);
        }
    } else {
        echo "No version specified. Use --install=VERSION to specify a version to install." . PHP_EOL;
        exit(1);
    }
}

if (!$isCli && !empty($_POST['tag']) && !$installer->getArchive($_POST['tag'])) {
    print 'A non-existent tag was selected';
    exit;
}

header('Content-Type: text/html; charset=utf-8');

class Installer
{
    /**
     * @var string
     */
    protected $zipUrl = 'https://github.com/evocms-community/evolution/archive/refs/%s.zip';

    /**
     * @var string
     */
    protected $apiUrl = 'https://api.github.com/repos/evocms-community/evolution/';

    /**
     * @return string
     */
    public function checkServer()
    {
        $errors = [];

        if (!extension_loaded('zip')) {
            $errors[] = '<div class="error">Cannot extract archives - ZIP extension is not enabled on this server.</div>';
        }

        if (!extension_loaded('curl')) {
            $errors[] =
                '<div class="error">Cannot download files - CURL extension is not enabled on this server.</div>';
        }

        if (!is_writable(__DIR__)) {
            $errors[] =
                '<div class="error">Cannot download files - The directory does not have write permission.</div>';
        }

        return implode($errors);
    }

    /**
     * @param $url
     *
     * @return bool|string
     */
    protected function curl($url)
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Evolution CE',
            ],
            CURLOPT_VERBOSE => true,
            CURLOPT_STDERR => $verbose = fopen('php://temp', 'w+')
        ]);

        $result = curl_exec($ch);
        
        if ($result === false) {
            if (php_sapi_name() === 'cli') {
                echo "Curl error: " . curl_error($ch) . PHP_EOL;
                rewind($verbose);
                $verboseLog = stream_get_contents($verbose);
                echo "Verbose information:\n", htmlspecialchars($verboseLog), "\n";
            }
        }
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (php_sapi_name() === 'cli') {
            echo "HTTP response code: " . $httpCode . PHP_EOL;
        }
        
        curl_close($ch);
        return $result;
    }

    /**
     * @param $tag
     *
     * @return bool
     */
    public function getArchive($tag)
    {
        $url = sprintf($this->zipUrl, $tag);

        $tempName = '_temp' . md5(time());
        $zipName = $tempName . '.zip';
        $baseDir = str_replace('\\', DIRECTORY_SEPARATOR, __DIR__);
        $tempDir = str_replace('\\', DIRECTORY_SEPARATOR, __DIR__) . DIRECTORY_SEPARATOR . $tempName;

        if (php_sapi_name() === 'cli') {
            echo "Downloading archive from $url..." . PHP_EOL;
        }

        $content = $this->curl($url);
        if ($content === false) {
            if (php_sapi_name() === 'cli') {
                echo "Failed to download archive!" . PHP_EOL;
            }
            return false;
        }

        $result = file_put_contents($zipName, $content);
        if ($result === false) {
            if (php_sapi_name() === 'cli') {
                echo "Failed to save archive to disk!" . PHP_EOL;
            }
            return false;
        }

        if (php_sapi_name() === 'cli') {
            echo "Extracting files..." . PHP_EOL;
        }

        $zip = new ZipArchive();
        if ($zip->open($baseDir . DIRECTORY_SEPARATOR . $zipName) !== true) {
            if (php_sapi_name() === 'cli') {
                echo "Failed to open zip archive!" . PHP_EOL;
            }
            return false;
        }

        $zip->extractTo($tempDir);
        $zip->close();

        if (is_file($baseDir . DIRECTORY_SEPARATOR . $zipName)) {
            unlink($baseDir . DIRECTORY_SEPARATOR . $zipName);
        }

        $dir = '';
        if ($handle = opendir($tempDir)) {
            while ($name = readdir($handle)) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $dir = $name;
            }

            closedir($handle);
        }

        if (php_sapi_name() === 'cli') {
            echo "Moving files to installation directory..." . PHP_EOL;
        }

        $this->moveFiles($tempDir . DIRECTORY_SEPARATOR . $dir, $baseDir);
        $this->removeFiles($tempDir);

        if (!php_sapi_name() === 'cli') {
            header('Location: install/index.php');
            unlink(__FILE__);
        }

        return true;
    }

    /**
     * @param $dir
     * @param $baseDir
     *
     * @return void
     */
    protected function moveFiles($dir, $baseDir)
    {
        $dir = realpath($dir);
        $baseDir = realpath($baseDir);

        $iterator = new RecursiveDirectoryIterator($dir);
        $files = new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::SELF_FIRST);

        /**
         * @var string $name
         * @var SplFileInfo $file
         */
        foreach ($files as $name => $file) {
            $path = $baseDir . substr(dirname($name), strlen($dir));
            $this->checkDir($path);

            if ($file->isDir()) {
                $checkDir = realpath($baseDir . substr($name, strlen($dir)));
                $checkDir && $this->checkDir($checkDir);
            }

            if (is_writable($path) && $file->isFile()) {
                rename($name, $path . '/' . basename($name));
            }
        }
    }

    /**
     * @param string $folder
     *
     * @return void
     */
    protected function checkDir($folder)
    {
        if (is_dir($folder)) {
            return;
        }

        if (mkdir($folder) || is_dir($folder)) {
            return;
        }

        throw new RuntimeException(
            sprintf(
                'Directory "%s" was not created',
                $folder
            )
        );
    }

    /**
     * @param $dir
     *
     * @return void
     */
    protected function removeFiles($dir)
    {
        $iterator = new RecursiveDirectoryIterator($dir);
        $files = new RecursiveIteratorIterator($iterator, RecursiveIteratorIterator::CHILD_FIRST);

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getFilename() === '.' || $file->getFilename() === '..') {
                continue;
            }

            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        rmdir($dir);
    }

    /**
     * Get available branches from GitHub API
     *
     * @return array
     */
    public function getBranches()
    {
        $data = [];
        $branches = json_decode($this->curl($this->apiUrl . 'branches'), true);
        $ignore = [];

        if (is_array($branches)) {
            foreach ($branches as $branch) {
                if (isset($branch['name']) && !in_array($branch['name'], $ignore)) {
                    $data[] = [
                        'tag' => $branch['name'],
                        'name' => $branch['name']
                    ];
                }
            }
        }

        return $data;
    }

    /**
     * Get available releases from GitHub API
     *
     * @return array
     */
    public function getReleases()
    {
        $data = [];
        $releases = json_decode($this->curl($this->apiUrl . 'releases'), true);

        if (is_array($releases)) {
            foreach ($releases as $release) {
                if (isset($release['tag_name']) && isset($release['name'])) {
                    $data[] = [
                        'tag' => $release['tag_name'],
                        'name' => $release['name']
                    ];
                }
            }
        }

        usort($data, function($a, $b) {
            return version_compare($b['tag'], $a['tag']) * -1;
        });

        return $data;
    }
}

if (!$isCli):
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <title>EVO Installer</title>
        <meta charset="UTF-8">
        <meta name="viewport"
              content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
        <meta http-equiv="X-UA-Compatible" content="ie=edge">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;700&display=swap" rel="stylesheet">
        <style>
            html, body {
                width: 100%;
                height: 100%;
                font-family: 'Inter', sans-serif;
                font-size: 16px;
            }
            body {
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0;
                padding: 0;
                min-width: 320px;
                background: #f1f1f1;
                color: #444;
                font-size: 1rem;
            }
            * {
                font-family: 'Inter', sans-serif;
                color: #444;
                box-sizing: border-box;
            }
            .app {
                padding: 2rem 2rem 6rem;
                max-width: 50rem;
                width: 100%;
            }
            header {
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 0 0 2rem;
                font-size: calc(3rem + 1vw);
            }
            .header-title {
                padding: 0 0 0 1.5rem;
                font-weight: 300;
                text-transform: uppercase;
            }
            .logo::before {
                content: "";
                display: block;
                position: relative;
                z-index: 1;
                width: calc(3rem + 1vw);
                height: calc(3rem + 1vw);
                border-radius: 50%;
                animation: rotate 2s linear infinite;
                box-shadow: 0.1875rem 0.1875rem 0 0 rgb(234 132 82), 0.5rem -0.25rem 0 0 rgb(111 163 219 / 70%), -0.25rem 0.375rem 0 0 rgb(112 193 92 / 74%), -0.375rem -0.25rem 0 0 rgb(147 205 99 / 78%);
            }
            .note {
                color: #aaa;
                font-size: 0.8rem;
                text-align: center;
                padding: 1rem;
            }
            footer {
                padding: 2rem;
                text-align: center;
                font-size: .75rem;
            }
            footer a {
                color: #aaa;
            }
            a {
                color: royalblue;
                text-decoration: none;
            }
            a:hover {
                text-decoration: underline;
            }
            h1 {
                margin: 0 0 .5rem;
                font-size: calc(1rem + 1vw);
                text-align: center;
                font-weight: 300;
            }
            select {
                appearance: none;
                padding: 1rem 2.5rem 1rem 1.5rem;
                width: 100%;
                display: block;
                background: white url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e") right 0.5rem center no-repeat;
                background-size: 1.5em 1.5em;
                border: 2px solid transparent;
                border-top-left-radius: .25rem;
                border-bottom-left-radius: .25rem;
                font-family: 'Inter', sans-serif;
                font-size: 1.25rem;
                font-weight: bold;
                outline: none;
            }
            select:focus {
                border-color: royalblue;
            }
            button {
                flex-grow: 0;
                flex-shrink: 0;
                padding: 1rem 1.5rem;
                background: mediumseagreen;
                color: white;
                border: 2px solid transparent;
                border-top-right-radius: .25rem;
                border-bottom-right-radius: .25rem;
                font-size: 1.25rem;
                cursor: pointer;
                outline: none;
                transition: .2s;
            }
            button:hover {
                background: seagreen;
            }
            .input-group {
                display: flex;
                flex-wrap: nowrap;
                flex-direction: row;
                height: 4rem;
            }
            .data-select {
                flex-grow: 1;
                display: flex;
            }
            .loader {
                margin: auto;
                border: .5rem solid #ddd;
                border-radius: 50%;
                border-top: .5rem solid mediumseagreen;
                width: 4rem;
                height: 4rem;
                animation: spinner 2s linear infinite;
            }
            .error {
                margin: 0 0 .25rem;
                padding: .5rem 1rem;
                background: crimson;
                border-radius: .25rem;
                color: white;
            }
            .cli-info {
                margin-top: 1rem;
                padding: 1rem;
                background: #e9f5ff;
                border-radius: .25rem;
                font-size: 0.9rem;
            }
            code {
                background: #f1f1f1;
                padding: 0.2rem 0.4rem;
                border-radius: 0.25rem;
                font-family: monospace;
            }
            @keyframes spinner {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            @keyframes rotate {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            @media (max-width: 990px) {
                html {
                    font-size: 14px;
                }
            }
            @media (max-width: 480px) {
                html {
                    font-size: 12px;
                }
            }
        </style>
    </head>
    <body>

    <div class="app">
        <header>
            <div class="logo"></div>
            <div class="header-title">Evolution</div>
        </header>

        <main>
            <h1>Choose Evolution CMS version to install</h1>
            <?php
            if ($errors = $installer->checkServer()) {
                echo $errors;
            } else {
                ?>
                <form>
                    <div class="loader" style="display: block"></div>
                    <div class="content" style="display: none">
                        <div class="input-group">
                            <div class="data-select"></div>
                            <button type="submit">Install &rarr;</button>
                        </div>
                    </div>
                </form>
                <?php
            }
            ?>

            <div class="cli-info">
                <strong>Command Line Installation:</strong>
                <p>You can also install Evolution CMS using the command line:</p>
                <p><code>php install.php --list</code> - Show available versions</p>
                <p><code>php install.php --install="tags/3.1.0"</code> - Install specific release</p>
                <p><code>php install.php --install="heads/master"</code> - Install from specific branch</p>
                <p><code>php install.php --help</code> - Show help information</p>
            </div>
        </main>

        <div class="note">
            If you choose one of the <span>Releases</span>, that release will be installed. If you choose one of the <span>Branches</span>, the most recent DEV version in that branch will be installed.
        </div>

        <footer>
            <a href="https://evo-cms.com/" target="_blank">By Evolution CMS Community</a>
        </footer>
    </div>

    <script>
      const app = {
        storageKey: 'EVO.INSTALL.DATA',
        apiUrl: 'https://api.github.com/repos/evocms-community/evolution/',

        data: {},

        async init () {
          this.loader(true)
          if (!localStorage[this.storageKey]) {
            this.data.branches = await this.getBranches()
            this.data.releases = await this.getReleases()

            localStorage[this.storageKey] = JSON.stringify(this.data)
          } else {
            this.data = JSON.parse(localStorage[this.storageKey])
          }

          let select = '<select name="tag">'

          select += '<optgroup label="Releases">'
          for (const release of this.data.releases) {
            select += '<option value="tags/' + release.tag + '">' + release.name + '</option>'
          }
          select += '</optgroup>'

          select += '<optgroup label="Branches">'
          for (const branch of this.data.branches) {
            select += '<option value="heads/' + branch.tag + '">' + branch.name + '</option>'
          }
          select += '</optgroup>'

          select += '</select>'

          document.querySelector('.data-select').innerHTML = select

          this.loader(false)

          document.querySelector('form button').addEventListener('click', function (event) {
            event.preventDefault()
            app.loader(true)

            fetch('<?= basename(__FILE__) ?>', {
              method: 'post',
              body: 'tag=' + document.querySelector('.data-select select').value,
              headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
              }
            }).then(response => {
              localStorage.removeItem(app.storageKey)
              location.href = response.url
            })
          })
        },

        loader (flag) {
          if (flag) {
            document.querySelector('.loader').style.display = 'block'
            document.querySelector('.content').style.display = 'none'
          } else {
            document.querySelector('.loader').style.display = 'none'
            document.querySelector('.content').style.display = 'block'
          }
        },

        async getBranches () {
          const data = []
          const branches = await fetch(this.apiUrl + 'branches').then(r => r.json())
          const ignore = []

          for (const branch of branches) {
            if (ignore.includes(branch.name)) {
              continue
            }

            data.push({
              tag: branch.name,
              name: branch.name
            })
          }

          return data
        },

        async getReleases () {
          const data = []
          const releases = await fetch(this.apiUrl + 'releases').then(r => r.json())

          for (const release of releases) {
            data.push({
              tag: release['tag_name'],
              name: release.name
            })
          }

          this.sort(data)

          return data
        },

        sort (data) {
          return data.sort((a, b) => b.tag < a.tag ? -1 : b.tag > a.tag ? 1 : 0)
        }
      }

      app.init()
    </script>
    </body>
    </html>
<?php endif; ?>