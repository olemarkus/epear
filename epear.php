<?php
/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

/** 
 * This file contains the epear program
 *
 * PHP Version 5
 *
 * @author    Ole Markus With <olemarkus@olemarkus.org>
 * @copyright 2010 Ole Markus With
 */


function cleanup_version($version) {
    return str_replace("beta", "_beta", $version);
}


require_once "PEAR/Config.php";
require_once "PEAR/PackageFile.php";

$package = $argv[1];

$config = PEAR_Config::singleton('', '');

$packageFile = new PEAR_PackageFile($config);

$channelName =  $config->get('default_channel');

$parsedName = $config->getRegistry()->parsePackageName($package, $channelName);

$channelUri = $parsedName["channel"];

$channel = $config->getRegistry()->getChannel($parsedName['channel']);

$base = $channel->getBaseURL('REST1.3', $parsedName['channel']);
$restv = "1.3";

if (!$base) {
    $base = $channel->getBaseURL('REST1.0', $parsedName['channel']);
    $restv = "1.0";
}

$options = array();

$rest = $config->getRest($restv, $options);

$state = 'alpha';


$url = $rest->getDownloadUrl($base, $parsedName, $state, false, $parsedName['channel']);

if (PEAR::isError($url)) {
    die("Failed to obtain url for $channelUri\n");

}

$uri = $url["url"] . ".tgz";

$name = $url["package"];
$version = $url["version"];


if (!is_dir('distfiles')) {
    mkdir('distfiles');
}

$filename = 'distfiles/' . $name . "-" . $version . ".tgz";

if (!file_exists($filename)) {
    passthru("wget $uri -O $filename");
}

$pf = $packageFile->fromAnyFile($filename, PEAR_VALIDATE_NORMAL);


$filelist = $pf->getInstallationFileList();
$fullfilelist = $pf->getFileList();

$rmfiles = array_diff_key($fullfilelist, $filelist);


$phpflags = array();
$php53flags = array();
$pearDeps = array();


$usedep["dom"] = "xml";
$usedep["mbstring"] = "unicode";


foreach ($pf->getDeps() as $dep) {
    if ($dep["optional"] == "yes") continue;

    switch ($dep["type"]) {
    case "pkg": 
        $prefix = "";
        if ($dep["channel"] == "pear.php.net") $prefix = "PEAR-";
        $rel = "";
        if ($dep["rel"] == "ge") {
            $rel = ">=";
        }

        //The key is used to prevent duplicates
        $pearDeps[$dep["name"]] = $rel . "dev-php/" . $prefix . $dep["name"] . "-" . cleanup_version($dep["version"]);
        break;
    case "ext":
        if (isset($usedep[$dep["name"]])) $dep["name"] = $usedep[$dep["name"]];
        if (!in_array($dep["name"], array("pcre","spl")))
            $php53flags[] = $dep["name"];
        $phpflags[] = $dep["name"];
        break;
    case "php":
        $phpver = $dep["version"];
    }
}

$phpdep = "";

if ($phpflags != $php53flags) {
    $phpdep = "|| ( <dev-lang/php-5.3[" . implode(",", $phpflags) . "] " .
        ">=dev-lang/php-5.3[" . implode(",", $php53flags) . "] )\n" ;
} elseif ($phpflags) {
    $phpdep = "dev-lang/php[" . implode(",", $phpflags). "]\n";
}

$phpdep .= ">=dev-lang/php-$phpver";


$peardep = implode("\n", $pearDeps);

$doins = "";

$prefix = ($channelUri == "pear.php.net") ? $prefix = "PEAR-" : "";

if (!is_dir("overlay/dev-php/" . $prefix . $pf->getName())) {
    mkdir("overlay/dev-php/" . $prefix . $pf->getName(), 0777, true);
}

$ename = $prefix . $pf->getName() . "-" . cleanup_version($pf->getVersion());
$euri = str_replace($ename, "\${P}", $uri);

$ebuildname = "overlay/dev-php/" . $prefix . $pf->getName() . "/" . 
    $ename . ".ebuild";

$ebuild = `head -n4 /usr/portage/skel.ebuild`;

$ebuild .= "EAPI=\"2\"\n";
$ebuild .= "inherit php-pear-r1\n";
$ebuild .= "KEYWORDS=\"~amd64\"\n";
$ebuild .= "SLOT=\"0\"\n";
$ebuild .= "DESCRIPTION=\"" . $pf->getSummary() . "\"\n";
$ebuild .= "LICENSE=\"" . str_replace(" License", "", $pf->getLicense()) . "\"\n";
$ebuild .= "HOMEPAGE=\"" . $parsedName['channel'] . "\"\n";
$ebuild .= "SRC_URI=\"" . $euri . "\"\n";
$ebuild .= "DEPEND=\"" . $phpdep . "\n" . $peardep . "\"\n";
$ebuild .= "RDEPEND=\"\${DEPEND}\"\n";
$ebuild .= "\n";

file_put_contents($ebuildname, $ebuild);

echo "Ebuild written to $ebuildname\n";
passthru("ebuild $ebuildname manifest");
