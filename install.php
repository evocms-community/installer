<?php

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('max_execution_time', 0);
set_time_limit(0);

if (extension_loaded('xdebug')) {
    ini_set('xdebug.max_nesting_level', 100000);
}

$installer = new Installer();

if (!empty($_POST['tag']) && $installer->getArchive($_POST['tag'])) {
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
     * @return string
     */
    public function checkServer()
    {
        $errors = [];

        if (!extension_loaded('curl')) {
            $errors[] = '<div class="error">Cannot download the files - CURL is not enabled on this server.</div>';
        }

        if (!is_writable(__DIR__)) {
            $errors[] =
                '<div class="error">Cannot download the files - The directory does not have write permission.</div>';
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
        ]);

        $result = curl_exec($ch);
        curl_close($ch);

        return $result;
    }

    /**
     * @param $tag
     *
     * @return true
     */
    public function getArchive($tag)
    {
        $url = sprintf($this->zipUrl, $tag);

        $zipName = 'fetch.zip';
        $baseDir = str_replace('\\', '/', __DIR__);
        $tempDir = str_replace('\\', '/', __DIR__) . '/_temp' . md5(time());

        file_put_contents('fetch.zip', $this->curl($url));

        $zip = new ZipArchive();
        $zip->open($baseDir . '/' . $zipName);
        $zip->extractTo($tempDir);
        $zip->close();

        if (is_file($baseDir . '/' . $zipName)) {
            unlink($baseDir . '/' . $zipName);
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

        $this->moveFiles($tempDir . '/' . $dir, $baseDir . '/');
        $this->removeFiles($tempDir);

        header('Location: install/index.php');

        unlink(__FILE__);

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

        foreach ($files as $name => $file) {
            $startsAt = substr(dirname($name), strlen($dir));
            $this->checkDir($baseDir . $startsAt);
            if ($file->isDir()) {
                $this->checkDir($baseDir . substr($name, strlen($dir)));
            }

            if (is_writable($baseDir . $startsAt) && $file->isFile()) {
                rename((string) $name, $baseDir . $startsAt . '/' . basename($name));
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

        throw new \RuntimeException(
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
}

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
            padding: 0 0 3rem;
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
        @keyframes spinner {
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
        <h1>Choose Evolution CMS version for install</h1>
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
    </main>

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
      const ignore = ['3.2.x', '2.0.x'];
      for (const branch of branches) {
        if(ignore.includes(branch.name)) continue;
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
