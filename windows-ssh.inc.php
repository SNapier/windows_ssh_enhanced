<?php
//
// SSH Proxy Config Wizard
// Copyright (c) 2010-2023 Nagios Enterprises, LLC. All rights reserved.
// Edited by SNapier
// Fix Template Inheritance
// Add Windows Performance Counters for CPU Performance
//

include_once(dirname(__FILE__) . '/../configwizardhelper.inc.php');

windows_ssh_configwizard_init();

function windows_ssh_configwizard_init()
{
    $name = "windows-ssh";
    $args = array(
        CONFIGWIZARD_NAME => $name,
        CONFIGWIZARD_VERSION => "2.1.0",
        CONFIGWIZARD_TYPE => CONFIGWIZARD_TYPE_MONITORING,
        CONFIGWIZARD_DESCRIPTION => _("Monitor a remote Windows Machine using SSH."),
        CONFIGWIZARD_DISPLAYTITLE => _("Windows SSH"),
        CONFIGWIZARD_FUNCTION => "windows_ssh_configwizard_func",
        CONFIGWIZARD_PREVIEWIMAGE => "windows_ssh.png",
        CONFIGWIZARD_FILTER_GROUPS => array('windows'),
        CONFIGWIZARD_REQUIRES_VERSION => 500
    );
    register_configwizard($name, $args);
}

function validate_ip_address($ip_address)
{
    return filter_var($ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || filter_var($ip_address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
}

function is_python_3()
{
    $versionString = shell_exec('python3 -c "import sys; print(sys.version_info[0])" 2>/dev/null');
    if ($versionString === null) {
        return false;
    }

    $pythonVersion = (int) $versionString;
    return ($pythonVersion >= 3);
}

/**
 * @param string $mode
 * @param null   $inargs
 * @param        $outargs
 * @param        $result
 *
 * @return string
 */
function windows_ssh_configwizard_func($mode = "", $inargs = null, &$outargs, &$result)
{
    $wizard_name = "windows_ssh";

    // Initialize return code and output
    $result = 0;
    $output = "";

    // Initialize output args - pass back the same data we got
    $outargs[CONFIGWIZARD_PASSBACK_DATA] = $inargs;

    switch ($mode) {
        case CONFIGWIZARD_MODE_GETSTAGE1HTML:
            $address = grab_array_var($inargs, "ip_address", "");
            $ssh_username = grab_array_var($inargs, "ssh_username", "");
            $services = grab_array_var($inargs, "services", "");
            $services_serial = grab_array_var($inargs, "services_serial", "");
            $serviceargs_serial = grab_array_var($inargs, "serviceargs_serial", "");

            ob_start();
            include __DIR__ . '/steps/step1.php';
            $output = ob_get_clean();

            break;

        case CONFIGWIZARD_MODE_VALIDATESTAGE1DATA:
            $address = grab_array_var($inargs, "ip_address", "");
            $address = nagiosccm_replace_user_macros($address);
            $ssh_username = grab_array_var($inargs, "ssh_username", "");

            $errors = 0;
            $errmsg = array();

            if (have_value($address) == false)
                $errmsg[$errors++] = _("No address specified.");
            else if (validate_ip_address($address) == false)
                $errmsg[$errors++] = _("Invalid address specified.");
            if (have_value($ssh_username) == false)
                $errmsg[$errors++] = _("No SSH username specified.");
            if (is_python_3() == false) {
                $errmsg[$errors++] = _("Python 3 is required to run this wizard.");
            }
            if ($errors > 0) {
                $outargs[CONFIGWIZARD_ERROR_MESSAGES] = $errmsg;
                $result = 1;
            }

            break;

        case CONFIGWIZARD_MODE_GETSTAGE2HTML:
            $address = grab_array_var($inargs, "ip_address");
            $ssh_username = grab_array_var($inargs, "ssh_username", "");

            $ha = @gethostbyaddr($address);
            if ($ha == "") {
                $ha = $address;
            }
            $hostname = grab_array_var($inargs, "hostname", $ha);
            $password = "";
            $services = "";

            $services_serial = grab_array_var($inargs, "services_serial", "");
            if ($services_serial != "")
                $services = json_decode(base64_decode($services_serial), true);

            #BUILD THE DEFAULT SERVICES TO BE DEPLOYED
            if (!is_array($services)) {
                $services_default = array(
                    "disk_volume" => array(),
                    "cpu_utilization" => array(),
                    "cpu_load" => array(),
                    "disk_io" => array(),
                    "services" => array(),
                );

                #HOST CHECK ENABLED
                $services_default["ping"]["monitor"] = 0;
                $services_default["tcp"]["monitor"] = 1;
                
                #RESOURCE CHECK DEFAULT THRESHOLDS
                $services_default["disk_volume"]["monitor"] = 1;
                $services_default["disk_volume"][0]["drive"] = "C:";
                $services_default["disk_volume"][0]["warning"] = "65";
                $services_default["disk_volume"][0]["critical"] = "100";
                $services_default["disk_volume"][0]["outputType"] = "GB";
                $services_default["disk_volume"][0]["metric"] = "Used";
                $services_default["disk_volume"][1]["warning"] = "65";
                $services_default["disk_volume"][1]["critical"] = "100";
                $services_default["disk_volume"][1]["outputType"] = "GB";
                $services_default["disk_volume"][1]["metric"] = "Used";
                
                #NANGIOS CPU 
                $services_default["cpu_load"]["monitor"] = 1;
                $services_default["cpu_load"]["warning"] = "80";
                $services_default["cpu_load"]["critical"] = "90";

                #CPU UTILIZATION
                #ADDED IN EDIT
                $services_default["cpu_utilization"]["monitor"] = 1;
                $services_default["cpu_utilization"]["metric"] = "User";
                $services_default["cpu_utilization"]["warning"] = "80";
                $services_default["cpu_utilization"]["critical"] = "90";
                
                #NAGIOS DISK USAGE
                $services_default["disk_io"]["monitor"] = 1;
                $services_default["disk_io"][0]["warning"] = "65";
                $services_default["disk_io"][0]["critical"] = "100";
                $services_default["disk_io"][0]["metric"] = "Total";
                $services_default["disk_io"][0]["disk_number"] = "0";
                $services_default["disk_io"][1]["warning"] = "65";
                $services_default["disk_io"][1]["critical"] = "100";
                $services_default["disk_io"][1]["metric"] = "Total";
                
                #NAGIOS WINDOWS SERVICE
                $services_default["windows_services"]["monitor"] = 1;
                $services_default["windows_services"][0]["service_name"] = "Spooler";
                $services_default["windows_services"][0]["display_name"] = "Print Spooler";
                $services_default["windows_services"][0]["expected_state"] = "Running";
                $services_default["windows_services"][1]["expected_state"] = "Running";

                #NAGIOS WINDOWS PROCESS
                $services_default["windows_processes"]["monitor"] = 1;
                $services_default["windows_processes"][0]["process_name"] = "notepad";
                $services_default["windows_processes"][0]["display_name"] = "Notepad";
                $services_default["windows_processes"][0]["outputType"] = "MB";
                $services_default["windows_processes"][0]["warning"] = "400";
                $services_default["windows_processes"][0]["critical"] = "500";
                $services_default["windows_processes"][0]["metric"] = "Memory";
                $services_default["windows_processes"][1]["outputType"] = "MB";
                $services_default["windows_processes"][1]["warning"] = "400";
                $services_default["windows_processes"][1]["critical"] = "500";
                $services_default["windows_processes"][1]["metric"] = "Memory";

                #NAGIOS WINDOWS MEMORY USAGE
                $services_default["memory_usage"]["monitor"] = 1;
                $services_default["memory_usage"]["warning"] = "1024";
                $services_default["memory_usage"]["critical"] = "512";
                $services_default["memory_usage"]["metric"] = "Available";
                $services_default["memory_usage"]["outputType"] = "MB";

                $services = grab_array_var($inargs, "services", $services_default);
            }

            $serviceargs = "";
            $serviceargs_serial = grab_array_var($inargs, "serviceargs_serial", "");
            if ($serviceargs_serial != "") {
                $serviceargs = json_decode(base64_decode($serviceargs_serial), true);
            }

            ob_start();
            include __DIR__ . '/steps/step2.php';
            $output = ob_get_clean();
            break;

            case CONFIGWIZARD_MODE_VALIDATESTAGE2DATA:
            $address = grab_array_var($inargs, "ip_address");
            $hostname = grab_array_var($inargs, "hostname");
            $ssh_username = grab_array_var($inargs, "ssh_username");
            $hostname = nagiosccm_replace_user_macros($hostname);

            $services = "";
            $services_serial = grab_array_var($inargs, "services_serial");
            if ($services_serial != "")
                $services = json_decode(base64_decode($services_serial), true);
            else
                $services = grab_array_var($inargs, "services");

            $serviceargs = "";
            $serviceargs_serial = grab_array_var($inargs, "serviceargs_serial");
            if ($serviceargs_serial != "")
                $serviceargs = json_decode(base64_decode($serviceargs_serial), true);
            else
                $serviceargs = grab_array_var($inargs, "serviceargs");

            $errors = 0;
            $errmsg = array();
            // $errmsg[$errors++] = _("No address specified.");

            
            #GRAB THE DEFAULT VALUES
            #TODO ADD CPU_UTILIZATION
            #TODO MAKE SERVICE EDITS
            if (isset($services["disk_volume"])) {
                foreach ($services["disk_volume"] as $key => $value) {
                    if ($value == "on") {
                        continue;
                    }
                    if (empty($services["disk_volume"][$key]["drive"])) {
                        continue;
                    }
                    if (empty($services["disk_volume"][$key]["warning"]) || empty($services["disk_volume"][$key]["critical"])) {
                        $errmsg[$errors++] = _("Volume Warning and Critical values are required if the drive is defined.");
                    }
                }
            }

            if (isset($services["disk_io"])) {
                foreach ($services["disk_io"] as $key => $value) {
                    if ($value == "on") {
                        continue;
                    }
                    if (empty($services["disk_io"][$key]["disk_number"])) {
                        continue;
                    }
                    if (empty($services["disk_io"][$key]["warning"]) || empty($services["disk_io"][$key]["critical"])) {
                        $errmsg[$errors++] = _("Disk Warning and Critical values are required if the disk number is defined.");
                    }
                }
            }

            if (isset($services["memory_usage"])) {
                if ($services["memory_usage"]["monitor"] == "on") {
                    if ($services["memory_usage"]["warning"] === null || $services["memory_usage"]["warning"] === '' || $services["memory_usage"]["critical"] === null || $services["memory_usage"]["critical"] === '') {
                        $errmsg[$errors++] = _("Memory Warning and Critical values are required if Memory Usage is enabled.");
                    }
                    if (!is_numeric($services["memory_usage"]["warning"]) || !is_numeric($services["memory_usage"]["critical"])) {
                        $errmsg[$errors++] = _("Memory Warning and Critical values must be numeric.");
                    }
                    if ($services["memory_usage"]["warning"] < 0 || $services["memory_usage"]["critical"] < 0) {
                        $errmsg[$errors++] = _("Memory Warning and Critical values must be positive.");
                    }
                }
            }

            if (isset($services["cpu_load"])) {
                if ($services["cpu_load"]["monitor"] == "on") {
                    if ($services["cpu_load"]["warning"] === null || $services["cpu_load"]["warning"] === '' || $services["cpu_load"]["critical"] === null || $services["cpu_load"]["critical"] === '') {
                        $errmsg[$errors++] = _("CPU Warning and Critical values are required if CPU Usage is enabled.");
                    }
                    if (!is_numeric($services["cpu_load"]["warning"]) || !is_numeric($services["cpu_load"]["critical"])) {
                        $errmsg[$errors++] = _("CPU Warning and Critical values must be numeric.");
                    }
                    if ($services["cpu_load"]["warning"] < 0 || $services["cpu_load"]["critical"] < 0) {
                        $errmsg[$errors++] = _("CPU Warning and Critical values must be positive.");
                    }
                }
            }

            #ADDED IN EDIT
            if (isset($services["cpu_utilization"])) {
                if ($services["cpu_utilization"]["monitor"] == "on") {
                    if ($services["cpu_utilization"]["warning"] === null || $services["cpu_utilization"]["warning"] === '' || $services["cpu_utilization"]["critical"] === null || $services["cpu_utilization"]["critical"] === '') {
                        $errmsg[$errors++] = _("CPU Warning and Critical values are required if CPU Usage is enabled.");
                    }
                    if ($services["cpu_utilization"]["warning"] < 0 || $services["cpu_utilization"]["critical"] < 0) {
                        $errmsg[$errors++] = _("CPU Utilization Warning and Critical values must be positive.");
                    }
                }
            }

            // for windows services, if service is set but display name is not, set display name to service name
            if (isset($services["windows_services"])) {
                foreach ($services["windows_services"] as $key => $value) {
                    if ($value == "on") {
                        continue;
                    }
                    if (empty($services["windows_services"][$key]["service_name"])) {
                        continue;
                    }
                    if (empty($services["windows_services"][$key]["display_name"])) {
                        $services["windows_services"][$key]["display_name"] = $services["windows_services"][$key]["service_name"];
                    }
                }
            }

            if ($errors > 0) {
                $outargs[CONFIGWIZARD_ERROR_MESSAGES] = $errmsg;
                $result = 1;
            }

            break;

        #TODO SEVICE EDITS
        case CONFIGWIZARD_MODE_GETSTAGE3HTML:
            $address = grab_array_var($inargs, "ip_address");
            $hostname = grab_array_var($inargs, "hostname");
            $ssh_username = grab_array_var($inargs, "ssh_username");
            $hostname = nagiosccm_replace_user_macros($hostname);

            $services = "";
            $services_serial = grab_array_var($inargs, "services_serial");
            if ($services_serial != "")
                $services = json_decode(base64_decode($services_serial), true);
            else
                $services = grab_array_var($inargs, "services");

            $serviceargs = "";
            $serviceargs_serial = grab_array_var($inargs, "serviceargs_serial");
            if ($serviceargs_serial != "")
                $serviceargs = json_decode(base64_decode($serviceargs_serial), true);
            else
                $serviceargs = grab_array_var($inargs, "serviceargs");

            if (isset($services["disk_volume"])) {
                foreach ($services["disk_volume"] as $key => $value) {
                    if ($value == "on") {
                        continue;
                    }
                    $unset = true;
                    foreach ($value as $key2 => $value2) {

                        if ($value2 != "") {
                            $unset = false;
                        }
                    }
                    if ($unset) {
                        unset($services["disk_volume"][$key]);
                    }
                }
            }

            if (isset($services["disk_io"])) {
                foreach ($services["disk_io"] as $key => $value) {
                    if ($value == "on") {
                        continue;
                    }
                    $unset = true;
                    foreach ($value as $key2 => $value2) {

                        if ($value2 != "") {
                            $unset = false;
                        }
                    }
                    if ($unset) {
                        unset($services["disk_io"][$key]);
                    }
                }
            }

            if (isset($services["windows_services"])) {
                foreach ($services["windows_services"] as $key => $value) {
                    if ($value == "on") {
                        continue;
                    }
                    $unset = true;
                    foreach ($value as $key2 => $value2) {

                        if ($value2 != "") {
                            $unset = false;
                        }
                    }
                    if ($unset) {
                        unset($services["windows_services"][$key]);
                    }
                }
            }

            if (isset($services["windows_processes"])) {
                foreach ($services["windows_processes"] as $key => $value) {
                    if ($value == "on") {
                        continue;
                    }
                    $unset = true;
                    foreach ($value as $key2 => $value2) {

                        if ($value2 != "") {
                            $unset = false;
                        }
                    }
                    if ($unset) {
                        unset($services["windows_processes"][$key]);
                    }
                }
            }

            $output = '
        <input type="hidden" name="ip_address" value="' . $address . '">
        <input type="hidden" name="hostname" value="' . $hostname . '">
        <input type="hidden" name="ssh_username" value="' . $ssh_username . '">
        <input type="hidden" name="services_serial" value="' . base64_encode(json_encode($services)) . '">
        <input type="hidden" name="serviceargs_serial" value="' . base64_encode(json_encode($serviceargs)) . '">';
            break;

        case CONFIGWIZARD_MODE_VALIDATESTAGE3DATA:

            break;

        case CONFIGWIZARD_MODE_GETFINALSTAGEHTML:

            break;

        case CONFIGWIZARD_MODE_GETOBJECTS:
            $hostname = grab_array_var($inargs, "hostname", "");
            $address = grab_array_var($inargs, "ip_address", "");

            $ssh_username = grab_array_var($inargs, "ssh_username", "");
            $hostaddress = $address;

            $services_serial = grab_array_var($inargs, "services_serial", "");
            $serviceargs_serial = grab_array_var($inargs, "serviceargs_serial", "");

            $services = json_decode(base64_decode($services_serial), true);
            $serviceargs = json_decode(base64_decode($serviceargs_serial), true);

            $meta_arr = array();
            $meta_arr["hostname"] = $hostname;
            $meta_arr["ip_address"] = $address;
            $meta_arr["ssh_username"] = $ssh_username;
            $meta_arr["services"] = $services;
            $meta_arr["serviceargs"] = $serviceargs;
            save_configwizard_object_meta($wizard_name, $hostname, "", $meta_arr);

            $objs = array();

            #MODIFIED TO USE WINDOWS TEMPLATES
            if (!host_exists($hostname)) {
                $objs[] = array(
                    "type" => OBJECTTYPE_HOST,
                    "use" => "xiwizard_windows_host_tcp",
                    "host_name" => $hostname,
                    "address" => $hostaddress,
                    "icon_image" => "windows_ssh.png",
                    "statusmap_image" => "windows_ssh.png",
                    "_xiwizard" => $wizard_name,
                );
            }

            #MODIFIED TO USE WINDOWS HOST TEMPLATES
            foreach ($services as $svc => $svcstate) {
                switch ($svc) {
                    #REMOVED CHECK COMMAND AS IT IS IN THE TEMPLATE
                    #REMOVED PORT AS IT IS NO LONGER NEEDED
                    case "tcp":
                        if($services["ping"]["monitor"] == "on") { break; }
                        $objs[] = array(
                            "type" => OBJECTTYPE_HOST,
                            "use" => "xiwizard_windows_host_tcp",
                            "host_name" => $hostname,
                            "address" => $hostaddress,
                            "icon_image" => "windows_ssh.png",
                            "statusmap_image" => "windows_ssh.png",
                            "_xiwizard" => $wizard_name,
                        );
                    break;

                    #MODIFIED TO USE WINDOWS HOST TEMPLATE 
                    case "ping":
                            $objs[] = array(
                                "type" => OBJECTTYPE_HOST,
                                "use" => "xiwizard_windows_host_icmp",
                                "host_name" => $hostname,
                                "address" => $hostaddress,
                                "icon_image" => "windows_ssh.png",
                                "statusmap_image" => "windows_ssh.png",
                                "_xiwizard" => $wizard_name,
                            );
                        break;

                    case "disk_volume":
                        if (($services["disk_volume"]["monitor"] != "on") || (!array_key_exists("disk_volume", $services))) {

                            break;
                        }

                        #MODIFIED TO USE XIWIZARD_GENERIC_SERVICE TEMPLATE
                        #RENAMED TO DISK_VOLUME
                        foreach ($services["disk_volume"] as $key => $value) {
                            if ($key === "monitor")
                                continue;
                            if (empty($services["disk_volume"][$key]["drive"]))
                                continue;

                            $checkcommand = "check_volume_by_ssh! ";
                            $checkcommand .= "-H " . $address . " ";
                            $checkcommand .= "-u " . $ssh_username . " ";
                            $checkcommand .= "-a '-volumename " . $services["disk_volume"][$key]["drive"] . "\ -outputType " . $services["disk_volume"][$key]["outputType"] . " -metric " . $services["disk_volume"][$key]["metric"] . "' ";
                            $objs[] = array(
                                "type" => OBJECTTYPE_SERVICE,
                                "host_name" => $hostname,
                                "service_description" => "Disk Volume " . $services["disk_volume"][$key]["drive"],
                                "use" => "xiwizard_generic_service",
                                "check_command" => $checkcommand,
                                "_xiwizard" => $wizard_name,
                            );
                        }
                        break;

                    #MODIFIED TO USE XIWIZARD_GENERIC_SERVICE TEMPLATE
                    #MODIFIED TO BE CPU_LOAD
                    case "cpu_load":
                        if (($services["cpu_load"]["monitor"] != "on") || (!array_key_exists("cpu_load", $services)))
                            break;

                        $checkcommand = "check_cpu_usage_by_ssh! ";
                        $checkcommand .= "-H " . $address . " ";
                        $checkcommand .= "-u " . $ssh_username . " ";
                        $checkcommand .= "-a '-warning " . $services["cpu_load"]["warning"] . " -critical " . $services["cpu_load"]["critical"] . "' ";

                        $objs[] = array(
                            "type" => OBJECTTYPE_SERVICE,
                            "host_name" => $hostname,
                            "service_description" => "CPU Load",
                            "use" => "xiwizard_generic_service",
                            "check_command" => $checkcommand,
                            "_xiwizard" => $wizard_name,
                        );
                        break;

                    #ADDED IN EDIT
                    case "cpu_utilization":
                        if (($services["cpu_utilization"]["monitor"] != "on") || (!array_key_exists("cpu_utilization", $services)))
                            break;

                        $checkcommand = "check_cpu_utilization_by_ssh! ";
                        $checkcommand .= "-H " . $address . " ";
                        $checkcommand .= "-u " . $ssh_username . " ";
                        $checkcommand .= "-a '-warning " . $services["cpu_utilization"]["warning"] . " -critical " . $services["cpu_utilization"]["critical"] . " -metric " . $services["cpu_utilization"]["metric"] ."' ";

                        $objs[] = array(
                            "type" => OBJECTTYPE_SERVICE,
                            "host_name" => $hostname,
                            "service_description" => "CPU Utilization",
                            "use" => "xiwizard_generic_service",
                            "check_command" => $checkcommand,
                            "_xiwizard" => $wizard_name,
                        );
                        break;
    
                    #MODIFIED TO USE XIWIZARD_GENERIC_SERVICE TEMPLATE
                    case "disk_io":
                        if (($services["disk_io"]["monitor"] != "on") || (!array_key_exists("disk_io", $services)))
                            break;
                        foreach ($services["disk_io"] as $key => $value) {
                            if ($key === "monitor")
                                continue;
                            if (($services["disk_io"][$key]["disk_number"] !== "0") && (empty($services["disk_io"][$key]["disk_number"]))) {
                                continue;
                            }

                            $checkcommand = "check_disk_usage_by_ssh! ";
                            $checkcommand .= "-H " . $address . " ";
                            $checkcommand .= "-u " . $ssh_username . " ";
                            $checkcommand .= "-a '-metric " . $services["disk_io"][$key]["metric"] . " -diskNum " . $services["disk_io"][$key]["disk_number"] . " -warning " . $services["disk_io"][$key]["warning"] . " -critical " . $services["disk_io"][$key]["critical"] . "' ";

                            $objs[] = array(
                                "type" => OBJECTTYPE_SERVICE,
                                "host_name" => $hostname,
                                "service_description" => "Disk Number: " . $services["disk_io"][$key]["disk_number"],
                                "use" => "xiwizard_generic_service",
                                "check_command" => $checkcommand,
                                "_xiwizard" => $wizard_name,
                            );
                        }
                        break;

                    #MODIFIED TO USE XIWIZARD_GENERIC_SERVICE TEMPLATE
                    case "windows_services":
                        if (($services["windows_services"]["monitor"] != "on") || (!array_key_exists("windows_services", $services)))
                            break;

                        foreach ($services["windows_services"] as $key => $value) {
                            if ($key === "monitor")
                                continue;
                            if (empty($services["windows_services"][$key]["service_name"]))
                                continue;

                            $checkcommand = "check_windows_services_by_ssh! ";
                            $checkcommand .= "-H " . $address . " ";
                            $checkcommand .= "-u " . $ssh_username . " ";
                            $checkcommand .= "-a '-expectedstate " . $services["windows_services"][$key]["expected_state"] . " -servicename " . $services["windows_services"][$key]["service_name"] . "' ";

                            $objs[] = array(
                                "type" => OBJECTTYPE_SERVICE,
                                "host_name" => $hostname,
                                "service_description" => $services["windows_services"][$key]["display_name"],
                                "use" => "xiwizard_generic_service",
                                "check_command" => $checkcommand,
                                "_xiwizard" => $wizard_name,
                            );
                        }
                        break;

                    #MODIFIED TO USE XIWIZARD_GENERIC_SERVICE TEMPLATE
                    case "memory_usage":
                        if (($services["memory_usage"]["monitor"] != "on") || (!array_key_exists("memory_usage", $services)))
                            break;

                        $checkcommand = "check_windows_memory_by_ssh! ";
                        $checkcommand .= "-H " . $address . " ";
                        $checkcommand .= "-u " . $ssh_username . " ";
                        $checkcommand .= "-a '-outputType " . $services["memory_usage"]["outputType"] . " -metric " . $services["memory_usage"]["metric"] . " -warning " . $services["memory_usage"]["warning"] . " -critical " . $services["memory_usage"]["critical"] . "' ";
                        $objs[] = array(
                            "type" => OBJECTTYPE_SERVICE,
                            "host_name" => $hostname,
                            "service_description" => "Memory Usage",
                            "use" => "xiwizard_generic_service",
                            "check_command" => $checkcommand,
                            "_xiwizard" => $wizard_name,
                        );
                        break;

                    #MODIFIED TO USE XIWIZARD_GENERIC_SERVICE TEMPLATE
                    case "windows_processes":
                        if (($services["windows_processes"]["monitor"] != "on") || (!array_key_exists("windows_processes", $services)))
                            break;

                        foreach ($services["windows_processes"] as $key => $value) {
                            if ($key === "monitor")
                                continue;
                            if (empty($services["windows_processes"][$key]["process_name"]))
                                continue;

                            $checkcommand = "check_windows_processes_by_ssh! ";
                            $checkcommand .= "-H " . $address . " ";
                            $checkcommand .= "-u " . $ssh_username . " ";
                            $checkcommand .= "-a '-processname " . $services["windows_processes"][$key]["process_name"] . " -metric " . $services["windows_processes"][$key]["metric"];
                            if ($services["windows_processes"][$key]["metric"] == "Memory") {
                                $checkcommand .= " -outputType " . $services["windows_processes"][$key]["outputType"];
                            }
                            $checkcommand .= " -warning " . $services["windows_processes"][$key]["warning"] . " -critical " . $services["windows_processes"][$key]["critical"] . "' ";

                            $objs[] = array(
                                "type" => OBJECTTYPE_SERVICE,
                                "host_name" => $hostname,
                                "service_description" => $services["windows_processes"][$key]["display_name"],
                                "use" => "xiwizard_generic_service",
                                "check_command" => $checkcommand,
                                "_xiwizard" => $wizard_name,
                            );
                        }
                        break;

                    default:

                        break;
                }
            }
            // Keep this for debugging
            // echo "<pre>";
            // print_r($objs);
            // echo "</pre>";
            $outargs[CONFIGWIZARD_NAGIOS_OBJECTS] = $objs;
            break;

        default:

            break;
    }

    return $output;
}