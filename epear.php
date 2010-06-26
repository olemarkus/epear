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

require_once "PEAR/Config.php";
require_once "PEAR/PackageFile.php";

function cleanup_version($version) 
{
    return str_replace("beta", "_beta", $version);
}

function get_package_name($name, $includeCategory = true) {
    $category = "dev-php";
    if (preg_match("/^ezc/", $name)) $category = "dev-php5";
    if ($name == "PHPUnit") {
        $name = "phpunit";
        $category = "dev-php5";
    }

    return $includeCategory ? $category . "/" . $name : $name;
}

function get_channel_prefix($channelUri) 
{
    $prefix = "";
    if ($channelUri == "pear.php.net") $prefix = "PEAR-";
    if ($channelUri == "components.ez.no") $prefix = "ezc-";
    return $prefix;
}

function generate_ebuild($pear_package) {
    echo "Generating ebuild for $pear_package\n";
    $config = PEAR_Config::singleton('', '');

    $packageFile = new PEAR_PackageFile($config);

    $channelName =  $config->get('default_channel');

    $parsedName = $config->getRegistry()->parsePackageName($pear_package,
        $channelName);

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


    $url = $rest->getDownloadUrl($base, $parsedName, $state, false,
        $parsedName['channel']);

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
    $postDeps = array();

    $usedep["dom"] = "xml";
    $usedep["mbstring"] = "unicode";

    foreach ($pf->getDeps() as $dep) {
        if ($dep["optional"] == "yes") continue;

        switch ($dep["type"]) {
        case "pkg":
            
            
            $prefix = get_channel_prefix($dep["channel"]);
            $rel = "";
            if ($dep["rel"] == "ge") {
                $rel = ">=";
            }
            
            $pkgname = $rel . get_package_name($prefix . $dep["name"]) . "-" .
                cleanup_version($dep["version"]);

            //Certain packages tend to create circular deps. We hack them into
            //PDEPEND
            if ($dep["name"] == "PHPUnit") {
                $postDeps[$dep["name"]] = $pkgname;
            } else {
                //The key is used to prevent duplicates
                $pearDeps[$dep["name"]] = $pkgname;
                if (!(shell_exec("portageq match / " . escapeshellarg($pkgname)))) {
                echo "Dependency $pkgname not found\n";
                    generate_ebuild($dep["channel"] . "/" . $dep["name"]);
                }
            }
            
            
            
            break;
        case "ext":
            if (isset($usedep[$dep["name"]])) $dep["name"] = $usedep[$dep["name"]];
            if (!in_array($dep["name"], array("pcre","spl","reflection")))
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
            "    >=dev-lang/php-5.3[" . implode(",", $php53flags) . "] )\n    " ;
    } elseif ($phpflags) {
        $phpdep = "dev-lang/php[" . implode(",", $phpflags). "]\n    ";
    }

    $phpdep .= ">=dev-lang/php-$phpver";


    $peardep = implode("\n    ", $pearDeps);

    $postdep = implode("\n    ", $postDeps);

    $doins = "";

    $prefix = get_channel_prefix($channelUri); 



    $ename = get_channel_prefix($channelUri) . $pf->getName();
    $myp = $pf->getName();

    $euri = str_replace($ename, $myp, $uri);

    if (!is_dir("overlay/" . get_package_name($ename))) {
        mkdir("overlay/" . get_package_name($ename), 0777, true);
    }

    $ebuildname = "overlay/" . get_package_name($ename)  . "/" . 
        get_package_name($ename, false) . "-" . cleanup_version($pf->getVersion()) .
".ebuild";

    $ebuild = `head -n4 /usr/portage/skel.ebuild`;

    $ebuild .= "EAPI=\"2\"\n";
    $ebuild .= "\n";
    $ebuild .= "PEAR_PV=\"" . $pf->getVersion() . "\"\n";
    $ebuild .= "PHP_PEAR_PKG_NAME=\"" . $pf->getName() . "\"\n";
    $ebuild .= "\n";
    $ebuild .= "inherit php-pear-r1\n";
    $ebuild .= "\n";
    $ebuild .= "KEYWORDS=\"~" . `portageq envvar ARCH` . "\"\n";
    $ebuild .= "SLOT=\"0\"\n";
    $ebuild .= "DESCRIPTION=\"" . $pf->getSummary() . "\"\n";
    $ebuild .= "LICENSE=\"" . str_replace(" License", "", $pf->getLicense()) .
"\"\n";
    $ebuild .= "HOMEPAGE=\"" . $parsedName['channel'] . "\"\n";
    $ebuild .= "SRC_URI=\"" . $euri . "\"\n";
    $ebuild .= "\n";
    $ebuild .= "DEPEND=\"" . $phpdep . "\n    " . $peardep . "\"\n";
    $ebuild .= "RDEPEND=\"\${DEPEND}\"\n";
    if ($postdep) {
        $ebuild .= "PDEPEND=\"$postdep\"\n";
    }
    $ebuild .= "\n";

    file_put_contents($ebuildname, $ebuild);

    echo "Ebuild written to $ebuildname\n";
    
    passthru("ebuild $ebuildname manifest");
}


generate_ebuild($package = $argv[1]);