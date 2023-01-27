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
    protected $dataUrl = 'https://api.github.com/repos/evocms-community/evolution/releases';

    /**
     * @var array
     */
    public $data = [];

    public function __construct()
    {
        $this->getReleases();
    }

    /**
     * @return string
     */
    public function checkServer()
    {
        $errors = [];

        if (!extension_loaded('curl')) {
            $errors[] = '<h2 class="warning">Cannot download the files - CURL is not enabled on this server.</h2>';
        }

        if (!is_writable(__DIR__)) {
            $errors[] =
                '<h2 class="warning">Cannot download the files - The directory does not have write permission.</h2>';
        }

        return implode('<br>', $errors);
    }

    /**
     * @return array
     */
    protected function getReleases()
    {
        $json = 'install.json';

        if (is_file($json)) {
            return $this->data = json_decode(file_get_contents($json), true);
        }

        $result = json_decode($this->curl($this->dataUrl), true);

        foreach ($result as $item) {
            $this->data[$item['tag_name']] = [
                'tag' => $item['tag_name'],
                'name' => $item['name'],
                'url' => $item['html_url'],
                'zip' => $item['zipball_url'],
                'tar' => $item['tarball_url'],
            ];
        }

        krsort($this->data);

        file_put_contents('install.json', json_encode($this->data));

        return $this->data;
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

    public function getArchive($tag)
    {
        if (!isset($this->data[$tag])) {
            return false;
        }

        $zipName = 'fetch.zip';
        $base_dir = str_replace('\\', '/', __DIR__);
        $temp_dir = str_replace('\\', '/', __DIR__) . '/_temp' . md5(time());

        file_put_contents('fetch.zip', $this->curl($this->data[$tag]['zip']));

        $zip = new ZipArchive();
        $zip->open($base_dir . '/' . $zipName);
        $zip->extractTo($temp_dir);
        $zip->close();

        if (is_file($base_dir . '/' . $zipName)) {
            unlink($base_dir . '/' . $zipName);
        }

        $dir = '';
        if ($handle = opendir($temp_dir)) {
            while ($name = readdir($handle)) {
                if ($name === '.' || $name === '..') {
                    continue;
                }

                $dir = $name;
            }

            closedir($handle);
        }

        $this->moveFiles($temp_dir . '/' . $dir, $base_dir . '/');
        $this->removeFiles($temp_dir);
        //unlink(__FILE__);
        header('Location: install/index.php');

        return true;
    }

    protected function moveFiles($src, $dest)
    {
        $path = realpath($src);
        $dest = realpath($dest);

        $objects = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($objects as $name => $object) {
            $startsAt = substr(dirname($name), strlen($path));
            $this->mmkDir($dest . $startsAt);
            if ($object->isDir()) {
                $this->mmkDir($dest . substr($name, strlen($path)));
            }

            if (is_writable($dest . $startsAt) && $object->isFile()) {
                rename((string) $name, $dest . $startsAt . '/' . basename($name));
            }
        }
    }

    /**
     * @param string $folder
     *
     * @return void
     */
    protected function mmkDir($folder)
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
            padding: 0 0 1rem;
        }
        .header-title {
            padding: 0 0 0 1.5rem;
            font-size: 2.5rem;
            font-weight: 300;
            text-transform: uppercase;
        }
        footer {
            padding: 1rem;
            text-align: center;
            font-size: .75rem;
            opacity: .5;
        }
        a {
            color: royalblue;
        }
        select {
            appearance: none;
            padding: 1rem 2.5rem 1rem 1rem;
            width: 100%;
            display: block;
            background: white url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e") right 0.5rem center no-repeat;
            background-size: 1.5em 1.5em;
            border: 2px solid transparent;
            border-top-left-radius: .25rem;
            border-bottom-left-radius: .25rem;
            font-family: 'Inter', sans-serif;
            font-size: 20px;
            font-weight: bold;
            outline: none;
        }
        select:focus {
            border-color: royalblue;
        }
        button {
            flex-grow: 0;
            flex-shrink: 0;
            padding: 1rem;
            background: mediumseagreen;
            color: white;
            border: none;
            border-top-right-radius: .25rem;
            border-bottom-right-radius: .25rem;
            font-size: 18px;
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
        }
        .release-select {
            flex-grow: 1;
        }
        .release-description {
            margin: 0 0 1rem;
        }
        .logo::before {
            content: "";
            display: block;
            position: relative;
            z-index: 1;
            width: 3rem;
            height: 3rem;
            border-radius: 50%;
            animation: rotate 2s linear infinite;
            box-shadow: 2px 2px 0 0 rgb(234 132 82), 7px -3px 0 0 rgb(111 163 219 / 70%), -3px 5px 0 0 rgb(112 193 92 / 74%), -5px -3px 0 0 rgb(147 205 99 / 78%);
        }
        .loader {
            margin: auto;
            border: .5rem solid #eee;
            border-radius: 50%;
            border-top: .5rem solid mediumseagreen;
            width: 3.62rem;
            height: 3.62rem;
            animation: spinner 2s linear infinite;
        }
        @keyframes spinner {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
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
        <h2>Choose Evolution CMS version for install</h2>
        <?php
        if ($errors = $installer->checkServer()) {
            echo $errors;
        } else {
            ?>
            <form>
                <div class="release-description"></div>
                <div class="input-group">
                    <div class="release-select"></div>
                    <button type="submit">Install &rarr;</button>
                </div>
            </form>
            <?php
        }
        ?>
    </main>

    <footer>
        By Evolution CMS Community
    </footer>
</div>

<script>
  const releases = <?= json_encode($installer->data) ?>;
  const form = document.querySelector('form')
  const button = document.querySelector('form button')
  const releaseSelect = document.querySelector('.release-select')
  const releaseDescription = document.querySelector('.release-description')

  function getInfo (release) {
    return '' +
      '<strong>Tag</strong>: ' + release['tag'] + '<br>' +
      '<strong>Name</strong>: ' + release['name'] + '<br>' +
      '<strong>Link</strong>: <a href="' + release['url'] + '" target="_blank">' + release['url'] + '</a>'
  }

  let select = '',
    description = ''

  for (const i in releases) {
    if (!releaseDescription.innerHTML) {
      releaseDescription.innerHTML = getInfo(releases[i])
    }

    select += '<option value="' + releases[i]['tag'] + '">' + releases[i]['name'] + '</option>'
  }

  releaseSelect.innerHTML = '<select name="tag">' + select + '</select>'

  releaseSelect.addEventListener('change', function (event) {
    releaseDescription.innerHTML = getInfo(releases[event.target.value])
  })

  button.addEventListener('click', function (event) {
    event.preventDefault()
    releaseSelect.parentElement.innerHTML = '<div class="loader"></div>'
    fetch('install.php', {
      method: 'post',
      body: 'tag=' + releaseSelect.firstElementChild.value,
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'
      }
    }).then(response => {
      location.href = response.url
    })
  })
</script>
</body>
</html>
