<?php
/* Pi-hole: A black hole for Internet advertisements
*  (c) 2017 Pi-hole, LLC (https://pi-hole.net)
*  Network-wide ad blocking via your own hardware.
*
*  This file is copyright under the latest version of the EUPL.
*  Please see LICENSE file for your rights under this license. */

    require "scripts/pi-hole/php/auth.php";
    require "scripts/pi-hole/php/password.php";
    require_once "scripts/pi-hole/php/FTL.php";
    require "scripts/pi-hole/php/theme.php";
    $scriptname = basename($_SERVER['SCRIPT_FILENAME']);

    check_cors();

    // Generate CSRF token
    if(empty($_SESSION['token'])) {
        $_SESSION['token'] = base64_encode(openssl_random_pseudo_bytes(32));
    }
    $token = $_SESSION['token'];

    // Try to get temperature value from different places (OS dependent)
    if(file_exists("/sys/class/thermal/thermal_zone0/temp"))
    {
        $output = rtrim(file_get_contents("/sys/class/thermal/thermal_zone0/temp"));
    }
    elseif (file_exists("/sys/class/hwmon/hwmon0/temp1_input"))
    {
        $output = rtrim(file_get_contents("/sys/class/hwmon/hwmon0/temp1_input"));
    }
    else
    {
        $output = "";
    }

    // Test if we succeeded in getting the temperature
    if(is_numeric($output))
    {
        // $output could be either 4-5 digits or 2-3, and we only divide by 1000 if it's 4-5
        // ex. 39007 vs 39
        $celsius = intVal($output);

        // If celsius is greater than 1 degree and is in the 4-5 digit format
        if($celsius > 1000) {
            // Use multiplication to get around the division-by-zero error
            $celsius *= 1e-3;
        }

        $kelvin = $celsius + 273.15;
        $fahrenheit = ($celsius*9./5)+32.0;

        if(isset($setupVars['TEMPERATUREUNIT']))
        {
            $temperatureunit = $setupVars['TEMPERATUREUNIT'];
        }
        else
        {
            $temperatureunit = "C";
        }
        // Override temperature unit setting if it is changed via Settings page
        if(isset($_POST["tempunit"]))
        {
            $temperatureunit = $_POST["tempunit"];
        }
        // Get user-defined temperature limit if set
        if(isset($setupVars['TEMPERATURE_LIMIT']))
        {
            $temperaturelimit = intval($setupVars['TEMPERATURE_LIMIT']);
        }
        else
        {
            $temperaturelimit = 60;
        }
    }
    else
    {
        // Nothing can be colder than -273.15 degree Celsius (= 0 Kelvin)
        // This is the minimum temperature possible (AKA absolute zero)
        $celsius = -273.16;
    }

    // Get load
    $loaddata = sys_getloadavg();
    foreach ($loaddata as $key => $value) {
        $loaddata[$key] = round($value, 2);
    }
    // Get number of processing units available to PHP
    // (may be less than the number of online processors)
    $nproc = shell_exec('nproc');
    if(!is_numeric($nproc))
    {
        $cpuinfo = file_get_contents('/proc/cpuinfo');
        preg_match_all('/^processor/m', $cpuinfo, $matches);
        $nproc = count($matches[0]);
    }

    // Get memory usage
    $data = explode("\n", file_get_contents("/proc/meminfo"));
    $meminfo = array();
    if(count($data) > 0)
    {
        foreach ($data as $line) {
            $expl = explode(":", trim($line));
            if(count($expl) == 2)
            {
                // remove " kB" from the end of the string and make it an integer
                $meminfo[$expl[0]] = intVal(substr($expl[1],0, -3));
            }
        }
        $memory_used = $meminfo["MemTotal"]-$meminfo["MemFree"]-$meminfo["Buffers"]-$meminfo["Cached"];
        $memory_total = $meminfo["MemTotal"];
        $memory_usage = $memory_used/$memory_total;
    }
    else
    {
        $memory_usage = -1;
    }

    if($auth) {
        // For session timer
        $maxlifetime = ini_get("session.gc_maxlifetime");

        // Generate CSRF token
        if(empty($_SESSION['token'])) {
            $_SESSION['token'] = base64_encode(openssl_random_pseudo_bytes(32));
        }
        $token = $_SESSION['token'];
    }

    if(isset($setupVars['WEBUIBOXEDLAYOUT']))
    {
        if($setupVars['WEBUIBOXEDLAYOUT'] === "boxed")
        {
            $boxedlayout = true;
        }
        else
        {
            $boxedlayout = false;
        }
    }
    else
    {
        $boxedlayout = true;
    }

    // Override layout setting if layout is changed via Settings page
    if(isset($_POST["field"]))
    {
        if($_POST["field"] === "webUI" && isset($_POST["boxedlayout"]))
        {
            $boxedlayout = true;
        }
        elseif($_POST["field"] === "webUI" && !isset($_POST["boxedlayout"]))
        {
            $boxedlayout = false;
        }
    }

    function pidofFTL()
    {
        return shell_exec("pidof pihole-FTL");
    }
    $FTLpid = intval(pidofFTL());
    $FTL = ($FTLpid !== 0 ? true : false);

    $piholeFTLConf = piholeFTLConfig();
?>
<!-- Pi-hole: A black hole for Internet advertisements
*  (c) 2017 Pi-hole, LLC (https://pi-hole.net)
*  Network-wide ad blocking via your own hardware.
*
*  This file is copyright under the latest version of the EUPL.
*  Please see LICENSE file for your rights under this license. -->
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self' https://api.github.com; script-src 'self' 'unsafe-eval' 'unsafe-inline'; img-src 'self' data:; style-src 'self' 'unsafe-inline'">
    <!-- Usually browsers proactively perform domain name resolution on links that the user may choose to follow. We disable DNS prefetching here -->
    <meta http-equiv="x-dns-prefetch-control" content="off">
    <meta http-equiv="cache-control" content="max-age=60,private">
    <!-- Tell the browser to be responsive to screen width -->
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pi-hole<?php if (gethostname()) {echo " - ", gethostname();} ?></title>

    <link rel="apple-touch-icon" href="img/favicons/apple-touch-icon.png" sizes="180x180">
    <link rel="icon" href="img/favicons/favicon-32x32.png" sizes="32x32" type="image/png">
    <link rel="icon" href="img/favicons/favicon-16x16.png" sizes="16x16" type="image/png">
    <link rel="manifest" href="img/favicons/manifest.json">
    <link rel="mask-icon" href="img/favicons/safari-pinned-tab.svg" color="#367fa9">
    <link rel="shortcut icon" href="img/favicons/favicon.ico">
    <meta name="msapplication-TileColor" content="#367fa9">
    <meta name="msapplication-TileImage" content="img/favicons/mstile-150x150.png">
    <meta name="theme-color" content="#367fa9">

<?php if ($darkmode) { ?>
    <style>
        html { background-color: #000; }
    </style>
<?php } ?>
    <link rel="stylesheet" href="style/vendor/SourceSansPro/SourceSansPro.css">
    <link rel="stylesheet" href="style/vendor/AdminLTE.min.css">
    <link rel="stylesheet" href="style/vendor/font-awesome-5.13.0/css/all.min.css">
    <link rel="stylesheet" href="style/vendor/datatables.min.css">
    <link rel="stylesheet" href="style/vendor/daterangepicker.css">
    <link rel="stylesheet" href="style/vendor/animate.min.css">

<?php if(in_array($scriptname, array("groups.php", "groups-clients.php", "groups-domains.php", "groups-adlists.php"))){ ?>
    <link rel="stylesheet" href="style/vendor/bootstrap/css/bootstrap-select.min.css">
    <link rel="stylesheet" href="style/vendor/bootstrap/css/bootstrap-toggle.min.css">
<?php } ?>
    <link rel="stylesheet" href="style/vendor/iCheck/<?php echo $checkbox_theme_name;?>/<?php echo $checkbox_theme_variant;?>.css">
    <link rel="stylesheet" href="style/pi-hole.css">
    <link rel="stylesheet" href="style/themes/<?php echo $theme; ?>.css">
    <noscript><link rel="stylesheet" href="style/vendor/js-warn.css"></noscript>

    <script src="scripts/vendor/jquery.min.js"></script>
    <script src="scripts/vendor/jquery-ui.min.js"></script>
    <script src="scripts/vendor/bootstrap.bundle.min.js"></script>
    <script src="scripts/vendor/adminlte.min.js"></script>
    <script src="scripts/vendor/bootstrap-notify.min.js"></script>

<?php if(in_array($scriptname, array("groups.php", "groups-clients.php", "groups-domains.php", "groups-adlists.php"))){ ?>
    <script src="style/vendor/bootstrap/js/bootstrap-select.min.js"></script>
    <script src="style/vendor/bootstrap/js/bootstrap-toggle.min.js"></script>
<?php } ?>

    <script src="scripts/vendor/datatables.min.js"></script>
    <script src="scripts/vendor/moment.min.js"></script>
    <script src="scripts/vendor/Chart.min.js"></script>
    <script src="scripts/vendor/iCheck.min.js"></script>
</head>
<body class="sidebar-mini accent-lightblue<?php echo $boxedlayout ? " layout-boxed" : "" ?>">
<noscript>
    <!-- JS Warning -->
    <div>
        <input type="checkbox" id="js-hide">
        <div class="js-warn" id="js-warn-exit"><h1>JavaScript Is Disabled</h1><p>JavaScript is required for the site to function.</p>
            <p>To learn how to enable JavaScript click <a href="https://www.enable-javascript.com/" rel="noopener" target="_blank">here</a></p><label for="js-hide">Close</label>
        </div>
    </div>
    <!-- /JS Warning -->
</noscript>
<?php
if($auth) {
    echo "<div id=\"token\" hidden>$token</div>";
}
?>

<!-- Send token to JS -->
<div id="checkbox_theme" hidden><?php echo $checkbox_theme_name; ?><?php if($checkbox_theme_name !== $checkbox_theme_variant){ echo "-$checkbox_theme_variant"; } ?></div>
<div id="enableTimer" hidden><?php if(file_exists("../custom_disable_timer")){ echo file_get_contents("../custom_disable_timer"); } ?></div>
<div class="wrapper">
    <nav class="main-header navbar navbar-expand navbar-dark navbar-lightblue">
        <!-- Left nav -->
        <ul class="navbar-nav">
            <li class="nav-item">
                <!-- Sidebar toggle button-->
                <a href="#" class="nav-link" data-widget="pushmenu" role="button">
                    <i class="nav-icon fas fa-bars" aria-hidden="true"></i>
                    <span class="sr-only">Toggle small sidebar</span>
                </a>
            </li>
        </ul>
        <!-- Right nav -->
        <ul class="navbar-nav ml-auto">
            <span class="navbar-text">
                <span class="d-none d-md-inline-block">hostname:</span>
                <code class="bg-white p-1"><?php echo gethostname(); ?></code>
            </span>
            <li class="nav-item d-none" id="pihole-diagnosis">
                <a class="nav-link" href="messages.php">
                    <i class="nav-icon fa fa-exclamation-triangle"></i>
                    <span class="label label-warning" id="pihole-diagnosis-count"></span>
                </a>
            </li>
            <li class="nav-item dropdown user-menu">
                <a class="nav-link dropdown-toggle" href="#" data-toggle="dropdown" aria-expanded="false">
                    <img src="img/logo.svg" class="user-image" alt="Pi-hole logo" style="border-radius: 0" width="25" height="25">
                    <span class="d-none d-md-inline">Pi-hole</span>
                </a>
                <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                    <!-- User image -->
                    <li class="user-header">
                        <img src="img/logo.svg" alt="Pi-hole Logo" style="border: 0" width="90" height="90">
                        <p>
                            Open Source Ad Blocker
                            <small>Designed For Raspberry Pi</small>
                        </p>
                    </li>
                    <!-- Menu Body -->
                    <li class="user-body">
                        <div class="row">
                            <div class="col-4 text-center">
                                <a class="btn-link" href="https://github.com/pi-hole" rel="noopener" target="_blank">GitHub</a>
                            </div>
                            <div class="col-4 text-center">
                                <a class="btn-link" href="https://pi-hole.net/" rel="noopener" target="_blank">Website</a>
                            </div>
                            <div class="col-4 text-center">
                                <a class="btn-link" href="https://github.com/pi-hole/pi-hole/releases" rel="noopener" target="_blank">Updates</a>
                            </div>
                            <div id="sessiontimer" class="col-12 text-center">
                                <strong>Session is valid for <span id="sessiontimercounter"><?php if($auth && strlen($pwhash) > 0){echo $maxlifetime;}else{echo "0";} ?></span></strong>
                            </div>
                        </div>
                    </li>
                    <!-- Menu Footer -->
                    <li class="user-footer text-center">
                        <!-- PayPal -->
                        <a href="https://pi-hole.net/donate/" rel="noopener" target="_blank">
                            <img src="img/donate.gif" alt="Donate" width="147" height="47">
                        </a>
                    </li>
                </ul>
            </li>
        </ul>
    </nav>

    <!-- Main Sidebar Container -->
    <aside class="main-sidebar sidebar-dark-lightblue elevation-2">
        <a class="brand-link navbar-lightblue" href="index.php">
            <img src="img/logo.svg" alt="Pi-hole logo" class="brand-image" width="33" height="33">
            <span class="brand-text font-weight-light">Pi-<strong>hole</strong></span>
        </a>

        <!-- Sidebar -->
        <div class="sidebar">
            <!-- Sidebar user panel -->
            <div class="user-panel mt-3 pb-3 mb-3 d-flex">
                <div class="image">
                    <img src="img/logo.svg" alt="Pi-hole logo" width="45" height="67" style="height: 67px;">
                </div>
                <div class="info">
                    <p>Status</p>
                    <?php
                    $pistatus = pihole_execute('status web');
                    if(isset($pistatus[0])) {
                        $pistatus = $pistatus[0];
                    } else {
                        $pistatus = null;
                    }
                    if ($pistatus === "1") {
                        echo '<a id="status"><i class="nav-icon fa fa-circle text-green-light"></i> Active</a>';
                    } elseif ($pistatus === "0") {
                        echo '<a id="status"><i class="nav-icon fa fa-circle text-red"></i> Offline</a>';
                    } elseif ($pistatus === "-1") {
                        echo '<a id="status"><i class="nav-icon fa fa-circle text-red"></i> DNS service not running</a>';
                    } else {
                        echo '<a id="status"><i class="nav-icon fa fa-circle text-orange"></i> Unknown</a>';
                    }

                    // CPU Temp
                    if($FTL)
                    {
                        if ($celsius >= -273.15) {
                            echo "<a id=\"temperature\"><i class=\"fa fa-fire ";
                            if ($celsius > $temperaturelimit) {
                                echo "text-red";
                            }
                            else
                            {
                                echo "text-vivid-blue";
                            }
                            echo "\"></i> Temp:&nbsp;";
                            if($temperatureunit === "F")
                            {
                                echo round($fahrenheit,1) . "&nbsp;&deg;F";
                            }
                            elseif($temperatureunit === "K")
                            {
                                echo round($kelvin,1) . "&nbsp;K";
                            }
                            else
                            {
                                echo round($celsius,1) . "&nbsp;&deg;C";
                            }
                            echo "</a>";
                        }
                    }
                    else
                    {
                        echo '<a id=\"temperature\"><i class="nav-icon fa fa-circle text-red"></i> FTL offline</a>';
                    }
                ?>
                <br/>
                <?php
                echo "<a title=\"Detected $nproc cores\"><i class=\"fa fa-circle ";
                    if ($loaddata[0] > $nproc) {
                        echo "text-red";
                    }
                    else
                    {
                        echo "text-green-light";
                    }
                    echo "\"></i> Load:&nbsp;&nbsp;" . $loaddata[0] . "&nbsp;&nbsp;" . $loaddata[1] . "&nbsp;&nbsp;". $loaddata[2] . "</a>";
                ?>
                <br/>
                <?php
                echo "<a><i class=\"fa fa-circle ";
                    if ($memory_usage > 0.75 || $memory_usage < 0.0) {
                        echo "text-red";
                    }
                    else
                    {
                        echo "text-green-light";
                    }
                    if($memory_usage > 0.0)
                    {
                        echo "\"></i> Memory usage:&nbsp;&nbsp;" . sprintf("%.1f",100.0*$memory_usage) . "&thinsp;%</a>";
                    }
                    else
                    {
                        echo "\"></i> Memory usage:&nbsp;&nbsp; N/A</a>";
                    }
                ?>
                </div>
            </div>

            <?php
            if($scriptname === "groups-domains.php" && isset($_GET['type']))
            {
                if($_GET["type"] === "white")
                {
                    $scriptname = "whitelist";
                }
                elseif($_GET["type"] === "black")
                {
                    $scriptname = "blacklist";
                }
            }
            if(!$auth && (!isset($indexpage) || isset($_GET['login'])))
            {
                $scriptname = "login";
            }
            ?>
            <!-- Sidebar Menu -->
            <nav class="mt-2">
                <ul class="nav nav-pills nav-sidebar flex-column" data-widget="treeview" role="menu" data-accordion="false">
                    <li class="nav-header text-uppercase">Main navigation</li>
                    <!-- Home Page -->
                    <li class="nav-item">
                        <a class="nav-link<?php echo $scriptname === "index.php" ? " active" : "" ?>" href="index.php">
                            <i class="nav-icon fa fa-home"></i>
                            <p>Dashboard</p>
                        </a>
                    </li>
                    <?php if($auth){ ?>
                    <!-- Query Log -->
                    <li class="nav-item">
                        <a class="nav-link<?php echo $scriptname === "queries.php" ? " active" : "" ?>" href="queries.php">
                            <i class="nav-icon fa fa-file-alt"></i>
                            <p>Query Log</p>
                        </a>
                    </li>
                    <li class="nav-item has-treeview">
                        <a class="nav-link<?php echo $scriptname === "db_queries.php" || $scriptname === "db_lists.php" || $scriptname === "db_graph.php" ? " active" : "" ?>" href="#">
                            <i class="nav-icon fa fa-clock"></i>
                            <p>
                                Long term data
                                <i class="fas fa-angle-left right"></i>
                            </p>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item">
                                <a class="nav-link<?php echo $scriptname === "db_graph.php" ? " active" : "" ?>" href="db_graph.php">
                                    <i class="nav-icon fa fa-file-alt"></i>
                                    <p>Graphics</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link<?php echo $scriptname === "db_queries.php" ? " active" : "" ?>" href="db_queries.php">
                                    <i class="nav-icon fa fa-file-alt"></i>
                                    <p>Query Log</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link<?php echo $scriptname === "db_lists.php" ? " active" : "" ?>" href="db_lists.php">
                                    <i class="nav-icon fa fa-file-alt"></i>
                                    <p>Top Lists</p>
                                </a>
                            </li>
                        </ul>
                    </li>
                    <!-- Whitelist -->
                    <li class="nav-item">
                        <a class="nav-link<?php echo $scriptname === "whitelist" ? " active" : "" ?>" href="groups-domains.php?type=white">
                            <i class="nav-icon fa fa-check-circle"></i>
                            <p>Whitelist</p>
                        </a>
                    </li>
                    <!-- Blacklist -->
                    <li class="nav-item">
                        <a class="nav-link<?php echo $scriptname === "blacklist" ? " active" : "" ?>" href="groups-domains.php?type=black">
                            <i class="nav-icon fa fa-ban"></i>
                            <p>Blacklist</p>
                        </a>
                    </li>
                    <!-- Local DNS Records -->
                    <li class="nav-item">
                        <a class="nav-link<?php echo $scriptname === "dns_records.php" ? " active" : "" ?>" href="dns_records.php">
                            <i class="nav-icon fa fa-address-book"></i>
                            <p>Local DNS Records</p>
                        </a>
                    </li>
                    <!-- Group Management -->
                    <li class="nav-item class="nav-item has-treeview">
                        <a class="nav-link<?php echo in_array($scriptname, array("groups.php", "groups-clients.php", "groups-domains.php", "groups-adlists.php")) ? " active" : "" ?>" href="#">
                            <i class="nav-icon fa fa-users-cog"></i>
                            <p>
                                Group Management
                                <i class="fas fa-angle-left right"></i>
                            </p>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item">
                                <a class="nav-link<?php echo $scriptname === "groups.php" ? " active" : "" ?>" href="groups.php">
                                    <i class="nav-icon fa fa-user-friends"></i>
                                    <p>Groups</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link<?php echo $scriptname === "groups-clients.php" ? " active" : "" ?>" href="groups-clients.php">
                                    <i class="nav-icon fa fa-laptop"></i>
                                    <p>Clients</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link<?php echo $scriptname === "groups-domains.php" ? " active" : "" ?>" href="groups-domains.php">
                                    <i class="nav-icon fa fa-list"></i>
                                    <p>Domains</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link<?php echo $scriptname === "groups-adlists.php" ? " active" : "" ?>" href="groups-adlists.php">
                                    <i class="nav-icon fa fa-shield-alt"></i>
                                    <p>Adlists</p>
                                </a>
                            </li>
                        </ul>
                    </li>
                    <!-- Toggle -->
                    <li class="nav-item has-treeview"<?php if ($pistatus == "0") { ?> hidden="true"<?php } ?> id="pihole-disable">
                        <a class="nav-link" href="#">
                            <i class="nav-icon fa fa-stop"></i>
                            <p>
                                Disable&nbsp;&nbsp;&nbsp;
                                <i class="fas fa-angle-left right"></i>
                                <span id="flip-status-disable"></span>
                            </p>
                        </a>
                        <ul class="nav nav-treeview">
                            <li class="nav-item">
                                <a class="nav-link" href="#" id="pihole-disable-permanently">
                                    <i class="nav-icon fa fa-stop"></i>
                                    <p>Permanently</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="#" id="pihole-disable-10s">
                                    <i class="nav-icon fa fa-clock"></i>
                                    <p>For 10 seconds</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="#" id="pihole-disable-30s">
                                    <i class="nav-icon fa fa-clock"></i>
                                    <p>For 30 seconds</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="#" id="pihole-disable-5m">
                                    <i class="nav-icon fa fas fa-clock"></i>
                                    <p>For 5 minutes</p>
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link" href="#" id="pihole-disable-cst" data-toggle="modal" data-target="#customDisableModal">
                                    <i class="nav-icon fa fa-clock"></i>
                                    <p>Custom time</p>
                                </a>
                            </li>
                        </ul>
                    </li>
                    <li id="pihole-enable" class="nav-item has-treeview"<?php if ($pistatus == "1") { ?> hidden="true"<?php } ?>>
                        <a class="nav-link" href="#">
                            <i class="nav-icon fa fa-play"></i>
                            <span id="enableLabel">Enable&nbsp;&nbsp;&nbsp;
                                <span id="flip-status-enable"></span>
                            </span>
                        </a>
                    </li>
                    <!-- Tools -->
                    <li class="nav-item has-treeview">
                        <a class="nav-link<?php echo in_array($scriptname, array("messages.php", "gravity.php", "queryads.php", "auditlog.php", "taillog.php", "taillog-FTL.php", "debug.php", "network.php")) ? " active" : "" ?>" href="#">
                          <i class="nav-icon fa fa-folder"></i>
                          <p>
                              Tools
                              <i class="fas fa-angle-left right"></i>
                          </p>
                        </a>
                        <ul class="nav nav-treeview">
                            <!-- Pi-hole diagnosis -->
                            <li class="nav-item">
                                <a class="nav-link<?php echo $scriptname === "messages.php" ? " active" : "" ?>" href="messages.php">
                                    <i class="nav-icon fa fa-stethoscope"></i>
                                    <p>Pi-hole diagnosis</p>
                                </a>
                            </li>
                            <!-- Run gravity.sh -->
                            <li class="nav-item">
                                <a class="nav-link<?php echo $scriptname === "gravity.php" ? " active" : "" ?>" href="gravity.php">
                                    <i class="nav-icon fa fa-arrow-circle-down"></i>
                                    <p>Update Gravity</p>
                                </a>
                            </li>
                            <!-- Query Lists -->
                            <li class="nav-item">
                                <a class="nav-link<?php echo $scriptname === "queryads.php" ? " active" : "" ?>" href="queryads.php">
                                    <i class="nav-icon fa fa-search"></i>
                                    <p>Query Lists</p>
                                </a>
                            </li>
                            <!-- Audit log -->
                            <li class="nav-item">
                                <a class="nav-link<?php echo $scriptname === "auditlog.php" ? " active" : "" ?>" href="auditlog.php">
                                    <i class="nav-icon fa fa-balance-scale"></i>
                                    <p>Audit log</p>
                                </a>
                            </li>
                            <!-- Tail pihole.log -->
                            <li class="nav-item">
                                <a class="nav-link<?php echo $scriptname === "taillog.php" ? " active" : "" ?>" href="taillog.php">
                                    <i class="nav-icon fa fa-list-ul"></i>
                                    <p>Tail pihole.log</p>
                                </a>
                            </li>
                            <!-- Tail pihole-FTL.log -->
                            <li class="nav-item">
                                <a class="nav-link<?php echo $scriptname === "taillog-FTL.php" ? " active" : "" ?>" href="taillog-FTL.php">
                                    <i class="nav-icon fa fa-list-ul"></i>
                                    <p>Tail pihole-FTL.log</p>
                                </a>
                            </li>
                            <!-- Generate debug log -->
                            <li class="nav-item">
                                <a class="nav-link<?php echo $scriptname === "debug.php" ? " active" : "" ?>" href="debug.php">
                                    <i class="nav-icon fa fa-ambulance"></i>
                                    <p>Generate debug log</p>
                                </a>
                            </li>
                            <!-- Network -->
                            <li class="nav-item">
                                <a class="nav-link<?php echo $scriptname === "network.php" ? " active" : "" ?>" href="network.php">
                                    <i class="nav-icon fa fa-network-wired"></i>
                                    <p>Network</p>
                                </a>
                            </li>
                        </ul>
                    </li>
                    <!-- Settings -->
                    <li class="nav-item">
                        <a class="nav-link<?php echo $scriptname === "settings.php" ? " active" : "" ?>" href="settings.php">
                            <i class="nav-icon fa fa-cogs"></i>
                            <p>Settings</p>
                        </a>
                    </li>
                    <!-- Logout -->
                    <?php
                    // Show Logout button if $auth is set and authorization is required
                    if(strlen($pwhash) > 0) { ?>
                    <li class="nav-item">
                        <a class="nav-link" href="?logout">
                            <i class="nav-icon fa fa-user-times"></i>
                            <p>Logout</p>
                        </a>
                    </li>
                    <?php } ?>
                    <?php } ?>
                    <!-- Login -->
                    <?php
                    // Show Login button if $auth is *not* set and authorization is required
                    if(strlen($pwhash) > 0 && !$auth) { ?>
                    <li class="nav-item">
                        <a class="nav-link<?php echo $scriptname === "login" ? " active" : "" ?>" href="index.php?login">
                            <i class="nav-icon fa far fa-user"></i>
                            <p>Login</p>
                        </a>
                    </li>
                    <?php } ?>
                    <!-- Donate -->
                    <li class="nav-item">
                        <a class="nav-link" href="https://pi-hole.net/donate/" rel="noopener" target="_blank">
                            <i class="nav-icon fa-paypal-icon fab fa-paypal"></i>
                            <p>Donate</p>
                        </a>
                    </li>
                    <?php if($auth){ ?>
                    <!-- Help -->
                    <li class="nav-item">
                        <a class="nav-link<?php echo $scriptname === "help.php" ? " active" : "" ?>" href="help.php">
                            <i class="nav-icon fa fa-question-circle"></i>
                            <p>Help</p>
                        </a>
                    </li>
                    <?php } ?>
                </ul>
            </nav>
        </div>
        <!-- /.sidebar -->
    </aside>

    <!-- Content Wrapper. Contains page content -->
    <div class="content-wrapper">
        <!-- Main content -->
        <section class="content">
            <div class="container-fluid">
<?php
    // If password is not equal to the password set
    // in the setupVars.conf file, then we skip any
    // content and just complete the page. If no
    // password is set at all, we keep the current
    // behavior: everything is always authorized
    // and will be displayed
    //
    // If auth is required and not set, i.e. no successfully logged in,
    // we show the reduced version of the summary (index) page
    if(!$auth && (!isset($indexpage) || isset($_GET['login']))){
        require "scripts/pi-hole/php/loginpage.php";
        require "footer.php";
        exit();
    }
?>
