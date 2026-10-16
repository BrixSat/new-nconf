<?php
// JSON response, get history entries

###
# Handle different histories (basic or detail)
if ( !empty($_GET["id"]) ){
    # Get History of a Item
    $basic_query = ' fk_id_item=?
                AND action <> "edited"';
    $basic_params = array($_GET["id"]);

    $show_item_links = FALSE;

}else{
    $show_item_links = TRUE;
    # Show all entries but still with sql filters (basic history entries, no details about services)
    $basic_query = 
              ' (action="created"'
            . ' OR action="removed"'
            . ' OR action="edited"'
            . ' OR action="general" )'
            . ' OR action ="module"'
            . ' OR (action ="modified"'
                . ' AND (attr_name = "Class" OR attr_name = "Attribute"))';
    $basic_params = array();

}



/* Paging */
$sLimit = "";
if ( isset( $_GET['iDisplayStart'] ) AND ( !empty($_GET['iDisplayLength']) AND $_GET['iDisplayLength'] != "-1" ) )
{
    # ints only: escaping does not protect a value that is not inside quotes
    $sLimit = "LIMIT ".(int)$_GET['iDisplayStart'].", ".(int)$_GET['iDisplayLength'] /* sql-safe: int casts */;
}

/* Ordering */
if ( isset( $_GET['iSortCol_0'] ) )
{
    # column names come from fnColumnToField() and directions from a fixed list, never from the request
    $sOrder = "ORDER BY  ";
    for ( $i=0 ; $i<(int)$_GET['iSortingCols'] ; $i++ )
    {
        $sort_dir = ( isset($_GET['sSortDir_'.$i]) AND strtolower($_GET['sSortDir_'.$i]) === "desc" ) ? "desc" : "asc";
        $sOrder .= fnColumnToField( isset($_GET['iSortCol_'.$i]) ? (int)$_GET['iSortCol_'.$i] : -1 )."
            ".$sort_dir.", "; // sql-safe: allowlisted column and direction
    }
    $sOrder = substr_replace( $sOrder, "", -2 );
}

/* Filtering */
$sWhere = "";
if ( $_GET['sSearch'] != "" )
{
    # split each word on space
    $search_words = explode(" ", $_GET['sSearch']);
    $sWhere = "AND ( ";
    $search_params = array();
    $i = 0;
    foreach ($search_words AS $search_word) {
        # after the first search, add a AND
        if ($i > 0) $sWhere .= ' AND ';
        # concatenate all history fields with separator and search word
        # the word keeps its % and _ wildcards, as with the escaping used before
        $sWhere .= "CONCAT_WS(',', LOWER(user_str), action, attr_name, LOWER(attr_value), timestamp ) LIKE ?";
        $search_params[] = '%'.strtolower($search_word).'%';
        $i++;
    }
    $sWhere .= " ) ";
    
}

$sQuery = 'SELECT SQL_CALC_FOUND_ROWS *
    FROM History
    WHERE ('
        . $basic_query
    . ')' /* sql-safe: $basic_query is fixed SQL with placeholders */;

    $sQuery .= "
        $sWhere
        $sOrder
        $sLimit
    " /* sql-safe: $sWhere holds only placeholders, $sOrder and $sLimit are allowlisted/int */;


# for debugging query, but JSON load will then fail
if ($debug == "yes") echo $sQuery;


$sParams = array_merge($basic_params, isset($search_params) ? $search_params : array());
$rPrepared = \NConf\Db\Sql::run($dbh_obj, $sQuery, $sParams) or die('Query: '.$sQuery.'<br><br>'.$dbh_obj->error);
$rResult = $rPrepared->result();

$sQuery = "
    SELECT FOUND_ROWS()
";
$rResultFilterTotal = $dbh_obj->query($sQuery) or die($dbh_obj->error);
$aResultFilterTotal = $rResultFilterTotal->fetch_array();
$iFilteredTotal = $aResultFilterTotal[0];

$sQuery = "
    SELECT COUNT(id_hist)
    FROM   History
    WHERE "
    . $basic_query; // sql-safe: $basic_query is fixed SQL with placeholders

$rPreparedTotal = \NConf\Db\Sql::run($dbh_obj, $sQuery, $basic_params) or die($dbh_obj->error);
$rResultTotal = $rPreparedTotal->result();
$aResultTotal = $rResultTotal->fetch_array();
$iTotal = $aResultTotal[0];


if (function_exists("json_encode")){
    // If json_ecode we use the php function to encode the array
    // as example code from DataTables
    
    $aColumns = array( 'timestamp', 'user_str', 'action', 'attr_name', 'attr_value', 'id_hist' );
    
    $output = array(
        "sEcho" => intval($_GET['sEcho']),
        "iTotalRecords" => $iTotal,
        "iTotalDisplayRecords" => $iFilteredTotal,
        "aaData" => array()
    );
    while ( $aRow = $rResult->fetch_array(MYSQLI_ASSOC) )
    {
        # do some stuff before generating output

        # if object name contains password, and PASSWD_DISPLAY is 0 (made in called function), do not show password
        if ( stristr($aRow["attr_name"], "password") ){
            $aRow["attr_value"] = show_password($aRow["attr_value"]);
        }
        # item linking
        if ( !empty($aRow["fk_id_item"]) AND $show_item_links ){
            if ($aRow["action"] == "removed" AND $aRow["attr_name"] == "service"){
                # removed services will link to hosts service list view
                $aRow['attr_value'] = '<a href="modify_item_service.php?id='.$aRow["fk_id_item"].'">'.$aRow["attr_value"].'</a>';
            }else{
                # all other entries will link to its detail view
                $aRow['attr_value'] = '<a href="detail.php?id='.$aRow["fk_id_item"].'">'.$aRow["attr_value"].'</a>';
            }
        }else{
            # entries which are removed, do not have any link
        }
        
        # Move the wanted fields in correct order and using only the values for the rows
        $row = array();
        foreach ($aColumns AS $key){
            $row[] = $aRow[$key];
        }
        $output['aaData'][] = $row;
    }
     
    echo json_encode( $output );
    
}else{
    // else we have a manual process to create JSON data
    $sOutput = '{';
    $sOutput .= '"sEcho": '.intval($_GET['sEcho']).', ';
    $sOutput .= '"iTotalRecords": '.$iTotal.', ';
    $sOutput .= '"iTotalDisplayRecords": '.$iFilteredTotal.', ';
    $sOutput .= '"aaData": [ ';
    
    
    while ( $aRow = $rResult->fetch_array() )
    {
        # do some stuff bevore generating output
    
        # if object name contains password, and PASSWD_DISPLAY is 0 (made in called function), do not show password
        if ( stristr($aRow["attr_name"], "password") ){
            $aRow["attr_value"] = show_password($aRow["attr_value"]);
        }
        # item linking
        if ( !empty($aRow["fk_id_item"]) AND $show_item_links ){
            if ($aRow["action"] == "removed" AND $aRow["attr_name"] == "service"){
                # removed services will link to hosts service list view
                $aRow['attr_value'] = '<a href="modify_item_service.php?id='.$aRow["fk_id_item"].'">'.$aRow["attr_value"].'</a>';
            }else{
                # all other entries will link to its detail view
                $aRow['attr_value'] = '<a href="detail.php?id='.$aRow["fk_id_item"].'">'.$aRow["attr_value"].'</a>';
            }
        }else{
            # entries which are removed, do not have any link
        }
    
        # create output (JSON)
        $sOutput .= "[";
        $sOutput .= '"'.addslashes($aRow['timestamp']).'",';
        $sOutput .= '"'.addslashes($aRow['user_str']).'",';
        $sOutput .= '"'.addslashes($aRow['action']).'",';
        $sOutput .= '"'.addslashes($aRow['attr_name']).'",';
        $sOutput .= '"'.addslashes($aRow['attr_value']).'",';
        $sOutput .= '"'.addslashes($aRow['id_hist']).'"';
        $sOutput .= "],";
    }
    
    $sOutput = substr_replace( $sOutput, "", -1 );
    $sOutput .= '] }';
    // This should fix a json problem
    $sOutput = str_replace("\'", "'", $sOutput);
    
    echo $sOutput;
}


function fnColumnToField( $i )
{
    if ( $i == 0 )
        return "timestamp";
    else if ( $i == 1 )
        return "user_str";
    else if ( $i == 2 )
        return "action";
    else if ( $i == 3 )
        return "attr_name";
    else if ( $i == 4 )
        return "attr_value";
    else if ( $i == 5 )
        return "id_hist";
}


?>