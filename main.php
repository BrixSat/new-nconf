<?php
##
##  main CONFIG FILE,
##
##  all config files will be loaded here
##  also the functions will be loaded
##

# get this dirname
$config_dir = dirname(__FILE__).'/config/';
#
# NConf Specific configuration
#
require_once($config_dir.'/nconf.php');
# now we can use NCONFDIR as "PATH"
#
# NConf Version info
#
require_once(NCONFDIR.'/include/version.php');

#
# Authentication / login
#
require_once(NCONFDIR.'/config/authentication.php');

#
# NConf classes
#
require_once(NCONFDIR.'/include/includeAllClasses.php');

#
# mysql-DB settings
#
require_once(NCONFDIR.'/config/mysql.php');
#
# PHP 8.1+ makes mysqli throw exceptions on error by default. This codebase was
# written against the classic behaviour of returning FALSE and checking
# mysqli_error()/mysqli_errno(), so restore that mode application-wide.
#
if ( function_exists('mysqli_report') ){
    mysqli_report(MYSQLI_REPORT_OFF);
}
#
# mysql Initiate connection procedurally
#
$dbh = mysqli_connect(DBHOST,DBUSER,DBPASS,DBNAME);
//mysql_select_db(DBNAME);
#
# mysql Initiate connection object oriented
#
$dbh_obj = new mysqli(DBHOST,DBUSER,DBPASS,DBNAME);
if ($dbh_obj->connect_errno) {
    die("Verbindung fehlgeschlagen: " . $dbh_obj->connect_error);
}

#
# some misc gui things
#
require_once(NCONFDIR.'/include/gui.php');

#
# part for messages
#
require_once(NCONFDIR.'/include/messages.php');


##
## LOAD Functions
##
require_once(NCONFDIR.'/include/functions.php');

##
## start debug info
##
NConf_DEBUG::set('', 'DEBUG', 'Header / ACL / navigation debugging');


##
## LOAD Modules
##
require_once(NCONFDIR.'/include/modules.php');

?>
