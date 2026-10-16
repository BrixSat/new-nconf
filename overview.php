<?php

# Checked before head.php prints any HTML, so the error keeps its 400 status
require_once __DIR__.'/include/error_handler.php';

# xmode=pikett lets ordinary users edit on-call settings and implies class=contact
if ( !(isset($_GET["xmode"]) && $_GET["xmode"] === "pikett")
    && ( !isset($_GET["class"]) || !is_string($_GET["class"]) || $_GET["class"] === "" ) ){
    throw HttpException::badRequest('The "class" parameter is required.');
}

require_once 'include/head.php';

// Form action and url handling
if ( !isset($_GET["goto"]) ){
    $request_url = set_page();
}else{
    $request_url = $_SESSION["go_back_page"];
}

require_once 'include/overview_list.php';


# set Filters

// Class (mandatory, checked at the top of this file)
$class = isset($_GET["class"]) ? $_GET["class"] : "";

# special mode to allow ordinary users to change on-call settings
if (isset($_GET["xmode"]) && $_GET["xmode"] == "pikett"){
    $class = "contact";
}




# order, page size and filters are saved per class, so the list looks the same when coming back to it
$saved_list = isset($_SESSION["cache"][$class]["searchfilter"]) && is_array($_SESSION["cache"][$class]["searchfilter"])
    ? $_SESSION["cache"][$class]["searchfilter"]
    : array();

# ordering: goes into the SQL as is, so only values listed in overview_orders() are accepted
$allowed_orders = overview_orders($class);
$order = $allowed_orders[0];
foreach ( array($_GET["order"] ?? null, $saved_list["order"] ?? null) as $requested_order ){
    if ( is_string($requested_order) && in_array($requested_order, $allowed_orders, true) ){
        $order = $requested_order;
        break;
    }
}

# quantity: rows per page, "all" = no paging; an empty or unset OVERVIEW_QUANTITY_STANDARD means all
$default_quantity = 'all';
if ( defined('OVERVIEW_QUANTITY_STANDARD') && ctype_digit((string)OVERVIEW_QUANTITY_STANDARD)
    && (int)OVERVIEW_QUANTITY_STANDARD > 0 ){
    $default_quantity = (string)(int)OVERVIEW_QUANTITY_STANDARD;
}
$allowed_quantities = array_unique(array(QUANTITY_SMALL, QUANTITY_MEDIUM, QUANTITY_LARGE, 'all', $default_quantity));
$show_quantity = $default_quantity;
foreach ( array($_GET["quantity"] ?? null, $saved_list["quantity"] ?? null) as $requested_quantity ){
    if ( is_string($requested_quantity) && in_array($requested_quantity, $allowed_quantities, true) ){
        $show_quantity = $requested_quantity;
        break;
    }
}
$page_size = ($show_quantity === 'all') ? 0 : (int)$show_quantity;

# column filters: a request carrying filter[] replaces the saved set, even when every value is empty
$overview_columns = overview_columns($class);
$filters = overview_parse_filters(
    isset($_GET["filter"]) ? $_GET["filter"] : ($saved_list["filter"] ?? array()),
    $overview_columns
);

# start is not saved: a changed filter or order should begin at the first page
$start = filter_var($_GET["start"] ?? 0, FILTER_VALIDATE_INT, array('options' => array('min_range' => 0)));
if ($start === FALSE) $start = 0;

$_SESSION["cache"][$class]["searchfilter"] = array(
    "order"    => $order,
    "quantity" => $show_quantity,
    "filter"   => $filters,
);

# every list link is built from this state; empty filters are left out to keep the links short
$list_state = array('class' => $class);
if (isset($_GET["xmode"]) && $_GET["xmode"] == "pikett"){
    $list_state['xmode'] = 'pikett';
}
$list_state['order']    = $order;
$list_state['quantity'] = $show_quantity;
$list_state['filter']   = array_filter($filters, static fn($value) => $value !== '');




if ( (defined('CMDB_SERVERLIST_COMPARE') AND CMDB_SERVERLIST_COMPARE == 1) AND ( !isset($_SESSION["cmdb_serverlist"]) )  ){ 
    # load server list, if activated and shoudl be loaded
    # the new login system can directly go to overview without loading the server list after login, so do it here when not done yet
    $load_serverlist = 'include/modules/sunrise/load_serverlist.php';
    if (file_exists($load_serverlist) ){
        require_once ($load_serverlist);
    }
}




?>

<!-- jQuery part -->
<script type="text/javascript">
    $(document).ready(function(){

        // Clone
        $('#submit_clone').click(function() {
            var first_id = $('input:checked:first').val();
            // Check if there was a checkbox clicked
            if (first_id){
                $('#advanced').attr('action', 'clone_host.php?id=' + first_id);
            }else{
                $('#advanced').attr('action', 'clone_host.php');
            }
            $('#advanced').submit();
        });

        // Multimodify
        $('#submit_multimodify').click(function() {
            $('#advanced').attr('action', 'handle_item.php?item=<?php echo $class;?>&type=multimodify');
            $('#advanced').submit();
        });

        // Delete
        $('#submit_multidelete').click(function() {
            $('#advanced').attr('action', 'delete_item.php?type=multidelete');
            $('#advanced').submit();
        });

        // live(): the list table is replaced in place on every filter, sort or page change
        // Checkbox selection with textlink
        $('#text_selectall').live('click', function() {
            var all_checked = $('#checkbox_selectall').attr('checked');
            if (all_checked){
                $("#checkbox_selectall").attr('checked', false);
            }else{
                $("#checkbox_selectall").attr('checked', true);
            }

            update_checkboxes();

        });

        // Checkbox selection with checkbox
        $('#checkbox_selectall').live('click', function() {
            update_checkboxes();
        });

        // only the list's own checkboxes: the page holds just the current page of rows
        function update_checkboxes(){
            $('input:checkbox[name="advanced_items[]"]').attr('checked', $('#checkbox_selectall').is(':checked'));
        }



    });
</script>

<!-- END of jQuery part -->

<?php



# save overview page for a delete operation
$_SESSION["after_delete_page"] = $request_url;



// Page output begin
# title and add icon share one line; the add text lives in the icon's tooltip
echo '<div class="overview-title">';
echo NConf_HTML::page_title($class, '');

// Create link
    $add_text = htmlspecialchars('Add new '.$class, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $add_link =  '<div class="overview-add">';
            $add_item = get_image( array(  "type" => "design",
                                           "name" => "add",
                                           "size" => 16,
                                           "tooltip" => $add_text,
                                           "alt" => $add_text,
                                           "class" => "lighten ui-button"
                                        ) );
            $add_link .= '<a href="handle_item.php?item='.urlencode($class).'" aria-label="'.$add_text.'">'.$add_item.'</a>';
            $add_link .= '</div>';
    echo $add_link;
echo '</div>';


# filter controls live in the list table and join this form via form="", since forms cannot nest
echo '<form id="'.OVERVIEW_FILTER_FORM.'" method="get" action="overview.php" data-server-side></form>';

# open new form
echo '<form id="advanced" name="advanced" action="'.htmlspecialchars($request_url, ENT_QUOTES | ENT_HTML5, 'UTF-8').'" method="post">';

# set class for submit form
if (!empty($class) ){
    echo '<input type="hidden" id="class" name="class" value="'.$class.'">';
}




if( ( isset($class) ) AND ($class != "") ){

    # Querys
    if ($class == "host") {

        $query = '
    SELECT
       fk_id_item AS host_id,
       attr_value AS hostname,
       (SELECT attr_value 
             FROM ConfigValues,ConfigAttrs 
             WHERE id_attr=fk_id_attr 
                 AND attr_name="address" 
                 AND fk_id_item=host_id) AS IP,
       IF( (SELECT INET_ATON(IP)),(SELECT INET_ATON(IP)),(SELECT IP)) AS BIN_IP,
       (SELECT attr_value 
             FROM ConfigValues,ConfigAttrs,ConfigClasses,ItemLinks 
             WHERE id_attr=ConfigValues.fk_id_attr 
                 AND naming_attr="yes" 
                 AND ConfigValues.fk_id_item=fk_item_linked2 
                 AND id_class=fk_id_class 
                 AND config_class="nagios-collector" 
                 AND ItemLinks.fk_id_item=host_id) AS collector,
       (SELECT attr_value 
             FROM ConfigValues,ItemLinks,ConfigAttrs,ConfigClasses 
             WHERE ConfigValues.fk_id_item=ItemLinks.fk_item_linked2 
                 AND id_attr=ConfigValues.fk_id_attr 
                 AND naming_attr="yes" 
                 AND id_class=fk_id_class 
                 AND config_class="os" 
                 AND ItemLinks.fk_id_item=host_id) AS os,
       (SELECT ConfigValues.fk_id_item 
             FROM ConfigValues,ItemLinks,ConfigAttrs,ConfigClasses 
             WHERE ConfigValues.fk_id_item=ItemLinks.fk_item_linked2 
                 AND id_attr=ConfigValues.fk_id_attr 
                 AND naming_attr="yes" 
                 AND id_class=fk_id_class 
                 AND config_class="os" 
                 AND ItemLinks.fk_id_item=host_id) AS os_id,
       (SELECT attr_value
             FROM ConfigValues,ItemLinks,ConfigAttrs,ConfigClasses 
             WHERE ConfigValues.fk_id_item=ItemLinks.fk_item_linked2 
                 AND id_attr=ConfigValues.fk_id_attr 
                 AND attr_name="icon_image"
                 AND id_class=fk_id_class 
                 AND config_class="os" 
                 AND ItemLinks.fk_id_item=host_id) AS os_icon,
       (SELECT "true" 
            FROM ConfigValues, ConfigAttrs, ConfigClasses, ItemLinks 
            WHERE id_attr=ConfigValues.fk_id_attr 
                AND ConfigAttrs.fk_id_class=id_class 
                AND config_class="service" 
                AND attr_name="service_enabled" 
                AND attr_value="no"
                AND ConfigValues.fk_id_item=ItemLinks.fk_id_item 
                AND ItemLinks.fk_item_linked2=host_id LIMIT 1) AS service_disabled 
       FROM ConfigValues,ConfigAttrs,ConfigClasses 
       WHERE id_attr=fk_id_attr AND naming_attr="yes" 
           AND id_class=fk_id_class 
           AND config_class="'.$class.'"';


    }else{
        # class != "host"
        if ($class == "checkcommand"){
            # special query for checkcommand and its default service name
            $query = 'SELECT
                id_item,
                attr_value AS entryname,
                (SELECT attr_value
                    FROM ConfigValues, ConfigAttrs
                    WHERE ConfigValues.fk_id_item = id_item
                    AND ConfigAttrs.id_attr = ConfigValues.fk_id_attr
                    AND ConfigAttrs.attr_name = "default_service_name") AS default_service_name,
                IFNULL(
                    NULLIF(
                        (SELECT attr_value
                        FROM ConfigValues, ConfigAttrs
                        WHERE ConfigValues.fk_id_item = id_item
                            AND ConfigAttrs.id_attr = ConfigValues.fk_id_attr
                            AND ConfigAttrs.attr_name = "default_service_name"
                        )
                    , "")
                , attr_value) AS sorting
                FROM ConfigItems,ConfigValues,ConfigAttrs,ConfigClasses
                WHERE id_item=fk_id_item
                    AND id_attr=fk_id_attr
                    AND naming_attr="yes"
                    AND ConfigItems.fk_id_class=id_class
                    AND config_class="'.$class.'"';
        }elseif($class == "service"){
            # special query for service and its hostnames
            $query = 'SELECT
                    id_item,
                    attr_value AS entryname,
                    (SELECT attr_value FROM ConfigValues,ConfigAttrs,ConfigClasses,ItemLinks
                        WHERE fk_item_linked2=ConfigValues.fk_id_item
                            AND id_attr=ConfigValues.fk_id_attr
                            AND naming_attr="yes"
                            AND fk_id_class = id_class
                            AND config_class="host"
                            AND ItemLinks.fk_id_item=id_item) AS hostname,
                    (SELECT attr_value
                        FROM ConfigValues,ConfigAttrs
                        WHERE id_attr=fk_id_attr
                            AND attr_name="service_enabled"
                            AND fk_id_item=id_item) AS service_enabled
                    FROM ConfigItems,ConfigValues,ConfigAttrs,ConfigClasses
                    WHERE id_item=fk_id_item
                        AND id_attr=fk_id_attr
                        AND naming_attr="yes"
                        AND ConfigItems.fk_id_class=id_class
                        AND config_class="'.$class.'"';
        }else{
            $query = 'SELECT
                    id_item,
                    attr_value AS entryname
                    FROM ConfigItems,ConfigValues,ConfigAttrs,ConfigClasses
                    WHERE id_item=fk_id_item
                        AND id_attr=fk_id_attr
                        AND naming_attr="yes"
                        AND ConfigItems.fk_id_class=id_class
                        AND config_class="'.$class.'"';
        }
        
        # XMODE
        if(isset($_GET["xmode"]) && $_GET["xmode"] == "pikett"){
            if ( !empty($ONCALL_GROUPS) ){
                # first entry must be AND, all other are part of it with OR
                $oncall_i = 1;
                foreach ($ONCALL_GROUPS as $oncall_group){
                    if ($oncall_i == 1){
                        $query .= ' AND ( attr_value = "'.$oncall_group.'"';
                    }else{
                        $query .= ' OR attr_value = "'.$oncall_group.'"';
                    }
                    $oncall_i++;
                }
                #close query correct
                $query .= ' ) ';
            }
        }

    }

    # filter, order and page in SQL; $order is from overview_orders(), the id makes the order unique
    $id_column = ($class == "host") ? 'host_id' : 'id_item';
    $overview_page = overview_fetch_page(
        $dbh,
        $query,
        $overview_columns,
        $filters,
        $order.', '.$id_column.' ASC',
        $start,
        $page_size
    );
    $result = $overview_page->rows;
    $start  = $overview_page->start;


    # overview table in IE 8 will only do correct margin-top when previouse element has clear:both
    # IE 8 also needs more space for advanced box: make the empty diff 7px height
    echo '<div class="clearer" style="height: 7px"></div>';

    ##
    # show quantity
    ##
    # data-list-part: nconf_table_sort_filter.js swaps this table in place when the list changes
    $list_part = ' data-list-part="'.OVERVIEW_FILTER_FORM.'"';
    if ($class == "host"){
        echo '<table id="overview_head" class="overview_head" style="width: 100%;"'.$list_part.'>';
    }else{
        $table_width = 450;
        if ($class == "service") $table_width = 500;

        echo '<table id="overview_head" class="overview_head" style="width: '.$table_width.'px;"'.$list_part.'>';
    }

        echo '<tr>';

        echo '<td width="20%">
                <h2 class="content_header">Overview</h2>
              </td>';

        # pager: plain links carrying the whole list state; a missing link means that arrow is disabled
        $total      = $overview_page->total;
        $show_first = ($total > 0) ? $start + 1 : 0;
        $show_last  = ($page_size > 0) ? min($start + $page_size, $total) : $total;
        $has_prev   = $start > 0;
        $has_next   = $page_size > 0 && $start + $page_size < $total;
        $last_start = ($page_size > 0 && $total > 0) ? intdiv($total - 1, $page_size) * $page_size : 0;

        echo '<td id="overview_navigation" style="text-align: center; vertical-align: middle">';
            echo overview_pager_link('2first', ICON_LEFT_FIRST_ANIMATED,
                $has_prev ? overview_url($list_state, array('start' => 0)) : null);
            echo overview_pager_link('2previouse', ICON_LEFT_ANIMATED,
                $has_prev ? overview_url($list_state, array('start' => max(0, $start - $page_size))) : null);
            echo '<span> Entries '.$show_first.' - '.$show_last.' of '.$total;
            if ($total !== $overview_page->unfiltered){
                echo ' (filtered from '.$overview_page->unfiltered.')';
            }
            echo ' </span>';
            echo overview_pager_link('2next', ICON_RIGHT_ANIMATED,
                $has_next ? overview_url($list_state, array('start' => $start + $page_size)) : null);
            echo overview_pager_link('2last', ICON_RIGHT_LAST_ANIMATED,
                $has_next ? overview_url($list_state, array('start' => $last_start)) : null);
        echo '</td>';

        # selectable quantity; changing it goes back to the first page
        echo '<td class="overview_quantity" width="20%">';
            foreach ( array(QUANTITY_SMALL, QUANTITY_MEDIUM, QUANTITY_LARGE, 'all') as $quantity ){
                if ($quantity === $show_quantity){
                    echo '<span aria-current="true">'.$quantity.'</span>';
                }else{
                    $quantity_url = htmlspecialchars(overview_url($list_state, array('quantity' => $quantity)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    echo '<a href="'.$quantity_url.'" data-list-link>'.$quantity.'</a>';
                }
                echo '&nbsp;&nbsp;';
            }
        echo '</td>';


    # close table header div
    echo '</tr>';
    echo '</table>';



    # list table and the advanced actions box side by side
    echo '<div class="overview-body">';
    if ($class == "host"){
        echo '<table id="overview_list" class="ui-nconf-table ui-nconf-max-width ui-widget ui-widget-content"'.$list_part.'>';
        echo '<colgroup>
                <col width="30">
                <col>
                <col width="100">
                <col width="100">
                <col width="160">
                <col width="30">
                <col width="30">
                <col width="30">
             </colgroup>';

    }else{
        $table_width = 450;
        if ($class == "service") $table_width = 500;
        echo '<table id="overview_list" class="ui-nconf-table ui-widget ui-widget-content" style="min-width:400px" width="'.$table_width.'"'.$list_part.'>';

        echo '<colgroup>';
            echo '<col>';
            if ( !isset($_GET["xmode"]) ){
                echo '<col width="35">';
                echo '<col width="35">';
            }else{
                # xmode view 
                echo '<col width="60">';
            }
        echo '</colgroup>';
    }

    

    # Fetch column titles
    $query = 'SELECT ConfigAttrs.friendly_name
                            FROM ConfigAttrs,ConfigClasses
                            WHERE id_class=fk_id_class
                            AND naming_attr="yes"
                            AND config_class="'.$class.'"
                            ';
    $title_result = db_handler($query, 'result', "Friendly name");
    echo '<thead class="ui-widget-header">';
    echo '<tr>';
        if ($class == "host") {
            echo '<td width="30">'.FRIENDLY_NAME_OS_LOGO.'</td>';
        }
        $name_label = $class;
        while($entry = mysqli_fetch_assoc($title_result)){
            $name_label = strip_tags($entry["friendly_name"]);
            # only hosts are sortable by more than one column; other lists always show their sort mark
            $direction = overview_sort_direction($order, ($class == "host") ? 'hostname' : null);
            if ($class == "host"){
                $order_value = (!empty($order) AND $order ==  "hostname ASC") ? 'hostname DESC' : 'hostname ASC';
            }elseif ($class == "checkcommand"){
                # checkcommands could be sorted in 4 special ways:
                $ordering_default_service_name = FALSE;
                switch($order){
                    case "entryname ASC":
                        NConf_DEBUG::set(CHECKCOMMAND_ORDER_COMMAND_NAME_ASC, 'INFO', CHECKCOMMAND_ORDER_PRETEXT);
                        $order_value = 'entryname DESC';
                    break;
                    case "entryname DESC":
                        NConf_DEBUG::set(CHECKCOMMAND_ORDER_COMMAND_NAME_DESC, 'INFO', CHECKCOMMAND_ORDER_PRETEXT);
                        $order_value = 'sorting ASC, entryname ASC';
                        $ordering_default_service_name = TRUE;
                    break;
                    case "sorting ASC, entryname ASC":
                        NConf_DEBUG::set(CHECKCOMMAND_ORDER_DEFAULT_SERVICE_NAME_ASC, 'INFO', CHECKCOMMAND_ORDER_PRETEXT);
                        $order_value = 'sorting DESC, entryname DESC';
                        $ordering_default_service_name = TRUE;
                    break;
                    case "sorting DESC, entryname DESC":
                        NConf_DEBUG::set(CHECKCOMMAND_ORDER_DEFAULT_SERVICE_NAME_DESC, 'INFO', CHECKCOMMAND_ORDER_PRETEXT);
                        $order_value = 'entryname ASC';
                    break;
                }
            }elseif ($class == "service"){
                $order_value = (!empty($order) AND $order ==  "hostname ASC, entryname ASC") ? 'hostname DESC, entryname DESC' : 'hostname ASC, entryname ASC';
            }else{
                $order_value = (!empty($order) AND $order ==  "entryname ASC") ? 'entryname DESC' : 'entryname ASC';
            }
            echo overview_header_cell(
                $entry["friendly_name"],
                overview_url($list_state, array('order' => $order_value)),
                $direction
            );
        }
        if ($class == "host") {
            $order_value = ($order ==  "BIN_IP ASC") ? 'BIN_IP DESC' : 'BIN_IP ASC';
            echo overview_header_cell(
                FRIENDLY_NAME_IPADDRESS,
                overview_url($list_state, array('order' => $order_value)),
                overview_sort_direction($order, 'BIN_IP'),
                ' width=100'
            );
            $order_value = ($order ==  "collector ASC") ? 'collector DESC' : 'collector ASC';
            echo overview_header_cell(
                FRIENDLY_NAME_NAGIOSSERVER,
                overview_url($list_state, array('order' => $order_value)),
                overview_sort_direction($order, 'collector'),
                ' width=100'
            );
            $order_value = ($order ==  "os ASC") ? 'os DESC' : 'os ASC';
            echo overview_header_cell(
                FRIENDLY_NAME_OS,
                overview_url($list_state, array('order' => $order_value)),
                overview_sort_direction($order, 'os'),
                ' width=100'
            );
        }

        if ($class == "host") {
            echo '<td colspan="4" class="center">'.FRIENDLY_NAME_ACTIONS.'</td>';
        }elseif(!isset($_GET["xmode"])){
            echo '<td colspan="2" class="center">'.FRIENDLY_NAME_ACTIONS.'</td>';
        }else{
            echo '<td colspan="1" class="center">'.FRIENDLY_NAME_ACTIONS.'</td>';
        }

        if ( !isset($_GET["xmode"]) ){
            echo '<td id="advanced_box" name="advanced_box" class="center" style="width: 70px;">';
            echo '<input type="checkbox" id="checkbox_selectall" class="pointer"><a id="text_selectall" href="#selectall">select</a></td>';
        }

    echo "</tr>";

    # filter row; the hidden fields carry the rest of the list state, so Enter filters even without JS
    $filter_state = '';
    foreach ( array_diff_key($list_state, array('filter' => TRUE)) as $state_name => $state_value ){
        $filter_state .= '<input type="hidden" form="'.OVERVIEW_FILTER_FORM.'"'
            .' name="'.htmlspecialchars($state_name, ENT_QUOTES | ENT_HTML5, 'UTF-8').'"'
            .' value="'.htmlspecialchars($state_value, ENT_QUOTES | ENT_HTML5, 'UTF-8').'">';
    }
    # off-screen rather than hidden: a form with several text boxes only submits on Enter if it has a submit button
    $filter_state .= '<button type="submit" form="'.OVERVIEW_FILTER_FORM.'" tabindex="-1" aria-hidden="true"'
        .' style="position:absolute;left:-9999px">filter</button>';

    $filter_labels = array(
        'name'      => $name_label,
        'ip'        => strip_tags(FRIENDLY_NAME_IPADDRESS),
        'collector' => strip_tags(FRIENDLY_NAME_NAGIOSSERVER),
        'os'        => strip_tags(FRIENDLY_NAME_OS),
    );
    $filter_cells = array();
    foreach ($overview_columns as $column_key => $column){
        $filter_cells[] = overview_filter_control(
            $column,
            $filters[$column_key],
            $overview_page->options[$column_key] ?? array(),
            $filter_labels[$column_key]
        );
    }

    echo '<tr class="ntsf-filter-row">';
        if ($class == "host") {
            # OS logo column: icons only, nothing to filter on
            echo '<td>'.$filter_state.'</td>';
        }else{
            $filter_cells[0] = $filter_state.$filter_cells[0];
        }
        foreach ($filter_cells as $filter_cell){
            echo '<td>'.$filter_cell.'</td>';
        }
        if ($class == "host") {
            echo '<td colspan="4"></td>';
        }elseif(!isset($_GET["xmode"])){
            echo '<td colspan="2"></td>';
        }else{
            echo '<td colspan="1"></td>';
        }
        if ( !isset($_GET["xmode"]) ){
            echo '<td></td>';
        }
    echo '</tr>';

    echo '</thead>';
    echo '<tbody class="ui-widget-content">';

    # check for nothing found
    if ( mysqli_num_rows($result) == 0 ){
        echo '<tr class="color_list1 highlight"><td colspan=9>'.TXT_NOTHING_FOUND.'</td></tr>';
    }elseif ($class == "host") {
        # Show host overview    

        # result was generated near row 384 / 453....
        if ( $result != "" ){
            $count = 1;
            while($entry = mysqli_fetch_assoc($result)){

                # set list color
                if ((1 & $count) == 1){
                    $bgcolor = "color_list1";
                }else{
                    $bgcolor = "color_list2";
                }

                $nocol_style = "";

                // Compare hostname feature
                $compare_status = 0;  # default set to 0 (deactivated)
                if ( (defined('CMDB_SERVERLIST_COMPARE') AND CMDB_SERVERLIST_COMPARE == 1) AND ( isset($_SESSION["cmdb_serverlist"]) AND is_array($_SESSION["cmdb_serverlist"]) )  ){ 
                    $compare_status = compare_hostname($entry["hostname"], $_SESSION["cmdb_serverlist"]);
                }
                if ($compare_status == 2){
                    # status 2 = not in array
                    echo '<tr class="color_warning">';
                }else{
                    if($entry["collector"]){
                        echo '<tr class="'.$bgcolor.' highlight">';
                    }else{
                        echo '<tr class="ui-state-error highlight">';
                        $entry["collector"] = OVERVIEW_NOT_MONITORED;
                        $nocol_style = 'class="color_nomon_text"';
                    }
                }
                echo '<td style="text-align:center">';
                $os_icon_path = OS_LOGO_PATH.'/'.$entry["os_icon"];
                if ( file_exists($os_icon_path) ){
                    echo '<img src="'.$os_icon_path.'" alt="'.$entry["os"].'" '.OS_LOGO_SIZE.'>';
                }
                echo '</td>';
                echo '<td><a href="detail.php?class='.$class.'&id='.$entry["host_id"].'">'.$entry["hostname"].'</a></td>';
                echo '<td>'.$entry["IP"].'</td>';
                echo '<td '.$nocol_style.'>'.$entry["collector"].'</td>';
                echo '<td>'.$entry["os"].'</td>';
                echo '<td style="text-align:center"><a href="handle_item.php?item='.$class.'&amp;id='.$entry["host_id"].'">'.ICON_EDIT.'</a></td>';
                echo '<td style="text-align:center"><a href="delete_item.php?item='.$class.'&amp;ids='.$entry["host_id"].'">'.ICON_DELETE.'</a></td>';
                echo '<td style="text-align:center"><a href="modify_item_service.php?item='.$class.'&amp;id='.$entry["host_id"].'">';
                    # if all services are enabled gear-icon will be yellow
                    # red will show that there is a disabled service
                    if ( isset($entry["service_disabled"]) AND $entry["service_disabled"] == "true"){
                        echo ICON_SERVICES_DISABLED;
                    }else{
                        echo ICON_SERVICES;
                    }
                    echo '</a></td>';
                // clone button
                echo ( $class == "host" ) ? '<td style="text-align:center"><a href="clone_host.php?class='.$class.'&amp;id='.$entry["host_id"].'">'.ICON_CLONE.'</a></td>' : '';

                if ( !isset($_GET["xmode"]) ){
                    # Checkbox will now be shown all the times and it is not possible to disable them. Disabling code for now
                    # TODO: Remove swapable advanced checkboxes if really not needed anymore
                    echo '<td id="advanced_box" name="advanced_box" style="text-align:center;">';
                    echo '<input type="checkbox" name="advanced_items[]" value="'.$entry["host_id"].'" class="pointer"></td>';
                }
                echo "</tr>\n";
                $count++;
            }
        }

    }else{
    # all other classes

        if ($result != "") {
            $count = 1;
            while($entry = mysqli_fetch_assoc($result)){
                # class for not active services
                if( !empty($entry["service_enabled"]) AND $entry["service_enabled"] === "no"){
                    echo '<tr class="ui-state-error highlight">';
                }else{
                    # set list color
                    if((1 & $count) == 1){
                        echo '<tr class="color_list1 highlight">';
                    }else{
                        echo '<tr class="color_list2 highlight">';
                    }
                }


                # checkcommand name and default service name special handling
                if( ( isset($class) ) AND ($class == "checkcommand") ){
                    if ( strpos($order, "entryname") === FALSE ){
                        if ( !empty($entry["default_service_name"]) ){
                            $entry["entryname"] = $entry["default_service_name"].' ('.$entry["entryname"].')';
                        }
                    }else{
                        if ( !empty($entry["default_service_name"]) ){
                            $entry["entryname"] = $entry["entryname"].' ('.$entry["default_service_name"].')';
                        }
                    }
                }

                if( ( isset($class) ) AND ($class == "service") ){

                    echo '<td><a href="detail.php?class='.$class.'&id='.$entry["id_item"].'">'.$entry["hostname"].': '.$entry["entryname"].'</a></td>';

                }else{
                    if(isset($_GET["xmode"])){
                        echo '<td><a href="detail.php?class='.$class.'&id='.$entry["id_item"].'&xmode='.$entry["entryname"].'">'.$entry["entryname"].'</a></td>';
                    }else{
                        echo '<td><a href="detail.php?class='.$class.'&id='.$entry["id_item"].'">'.$entry["entryname"].'</a></td>';
                    }
                }

                if(isset($_GET["xmode"])){
                    echo '<td style="text-align:center"><a href="handle_item.php?item='.$class.'&xmode='.$entry["entryname"].'">'.ICON_EDIT.'</a></td>';
                }else{
                    echo '<td style="text-align:center"><a href="handle_item.php?item='.$class.'&amp;id='.$entry["id_item"].'">'.ICON_EDIT.'</a></td>';
                    echo '<td style="text-align:center"><a href="delete_item.php?item='.$class.'&amp;ids='.$entry["id_item"].'">'.ICON_DELETE.'</a></td>';
                }

                if ( !isset($_GET["xmode"]) ){
                    // Advanced checkbox
                    echo '<td id="advanced_box" name="advanced_box" style="text-align:center;">';
                        echo '<input type="checkbox" name="advanced_items[]" value="'.$entry["id_item"].'" class="pointer checkbox-small">';
                    echo '</td>';
                }

                echo "</tr>\n";

                $count++;
            }
        }
    }
    echo '</tbody>';
    echo '</table>';

    # Advanced Tab-View, right of the list
    if (!isset($_GET["xmode"])){
        require_once 'include/tabs/advanced.php';
    }
    echo '</div>';

}

echo '</form>';



mysqli_close($dbh);
require_once 'include/foot.php';

?>
