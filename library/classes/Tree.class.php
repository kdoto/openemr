<?php

define("ROOT_TYPE_ID", 1);
define("ROOT_TYPE_NAME", 2);

/**
 * class Tree
 * This class is a clean implementation of a modified preorder tree traversal hierachy to relational model
 * Don't use this class directly as it won't work, extend it and set the $this->_table variable, currently
 * this class needs its own sequence per table. MPTT uses a lot of self referential parent child relationships
 * and having ids that are more or less sequential makes human reading, fixing and reconstruction much easier.
 */

class Tree
{

    /*
	*	This is the name of the table this tree is stored in
	*	@var string
	*/
    var $_table;

    /*
	*	This is a lookup table so that you can get a node name or parent id from its id
	*	@var array
	*/
    var $_id_name;

    /*
	*	This is a db abstraction object compatible with ADODB
	*	@var object the constructor expects it to be available as $GLOBALS['adodb']['db']
	*/
    var $_db;

    /*
	*	The constructor takes a value and a flag determining if the value is the id of a the desired root node or the name
	*	@param mixed $root name or id of desired root node
	*	@param int $root_type optional flag indicating if $root is a name or id, defaults to id
	*/
    function __construct($root, $root_type = ROOT_TYPE_ID)
    {
        $this->_db = $GLOBALS['adodb']['db'];
        $this->_root = add_escape_custom($root);
        $this->_root_type = add_escape_custom($root_type);
        $this->load_tree();
    }

    function load_tree()
    {
        $root = $this->_root;
        $tree = array();
        $tree_tmp = array();

      //get the left and right value of the root node
        $sql = "SELECT * FROM " . $this->_table . " WHERE id='".$root."'";

        if ($this->root_type == ROOT_TYPE_NAME) {
            $sql = "SELECT * FROM " . $this->_table . " WHERE name='".$root."'";
        }

        $result = $this->_db->Execute($sql) or die("Error: " . $this->_db->ErrorMsg());
        $row = array();

        if ($result && !$result->EOF) {
            $row = $result->fields;
        } else {
            $this->tree = array();
        }

      // start with an empty right stack
        $right = array();

      // now, retrieve all descendants of the root node
        $sql = "SELECT * FROM " . $this->_table . " WHERE lft BETWEEN " . $row['lft'] . " AND " . $row['rght'] . " ORDER BY parent,name ASC;";
        $result = $this->_db->Execute($sql);
        $this->_id_name = array();


        while ($result && !$result->EOF) {
            $ar = array();
            $row = $result->fields;

            //create a lookup table of id to name for every node that will end up in this tree, this is used
            //by the array building code below to find the chain of parents for each node

            // ADDED below by BM on 06-2009 to translate categories, if applicable
            if ($this->_table == "categories") {
                $this->_id_name[$row['id']] = array("id" => $row['id'], "name" => xl_document_category($row['name']),
                "parent" => $row['parent'], "value" => $row['value'], "aco_spec" => $row['aco_spec']);
            } else {
                $this->_id_name[$row['id']] = array("id" => $row['id'], "name" => $row['name'], "parent" => $row['parent']);
            }

            // only check stack if there is one
            if (count($right)>0) {
                // check if we should remove a node from the stack
                while ($right[count($right)-1]<$row['rght']) {
                    array_pop($right);
                }
            }

            //set up necessary variables to then determine the chain of parents for each node
            $parent = $row['parent'];
            $loop = 0;

            //this is a string that gets evaled below to create the array representing the tree
            $ar_string = "[\"".($row['id']) ."\"] = \$row[\"value\"]";

            //if parent is 0 then the node has no parents, the number of nodes in the id_name lookup always includes any nodes
            //that could be the parent of any future node in the record set, the order is deterministic because of the algorithm
            while ($parent != 0 && $loop < count($this->_id_name)) {
                $ar_string = "[\"" . ($this->_id_name[$parent]['id']) . "\"]" . $ar_string;
                $loop++;
                $parent = $this->_id_name[$parent]['parent'];
            }

            $ar_string = '$ar' . $ar_string . ";";
            //echo $ar_string;

            //now eval the string to create the tree array
            //there must be a more efficient way to do this than eval?
            eval($ar_string);

            //merge the evaled array with all of the already exsiting tree elements,
            //merge recursive is used so that no keys are replaced in other words a key
            //with a specific value will not be replace but instead that value will be turned into an array
            //consisting of the previous value and the new value
            $tree = array_merge_n($tree, $ar);

            // add this node to the stack
            $right[] = $row['rght'];
            $result->MoveNext();
        }

        $this->tree = $tree;
    }

    /*
	*	This function completely rebuilds a tree starting from parent to ensure that all of its preorder values
	*	are integrous.
	*	Upside is that it fixes any kind of goofiness, downside is that it is recursive and consequently
	*	exponentially expensive with the size of the tree.
	*	On adds and deletes the tree does dynamic updates as appropriate to maintain integrity of the algorithm,
	*	however you can still force it to do goofy things and afterwards you will need this function to fix it.
	*	If you need to do a huge number of adds or deletes it will be much faster to act directly on the db and then
	*	call this to fix the mess than to use the add and delete functions.
	*	@param int $parent id of the node you would like to rebuild all nodes below
	*	@param int $left optional proper left value of the node you are rebuilding below, then used recursively
	*/
    function rebuild_tree($parent, $left = null)
    {

      //if no left is supplied assume the existing left is proper
        if ($left == null) {
            $sql = "SELECT lft FROM " . $this->_table . " WHERE id='" . $parent . "';";
            $result = $this->_db->Execute($sql) or die("Error: " . $this->_db->ErrorMsg());

            if ($result && !$result->EOF) {
                $left = $result->fields['lft'];
            } else {
                //the node you are rebuilding below if goofed up and you didn't supply a proper value
                //nothing we can do so error
                die("Error: The node you are rebuilding from could not be found, please supply an existing node id.");
            }
        }

      // get all children of this node
        $sql = "SELECT id FROM " . $this->_table . " WHERE parent='" . $parent . "' ORDER BY id;";
        $result = $this->_db->Execute($sql) or die("Error: " . $this->_db->ErrorMsg());

      // the right value of this node is the left value + 1
        $right = $left+1;

        while ($result && !$result->EOF) {
            $row = $result->fields;
            // recursive execution of this function for each
            // child of this node
            // $right is the current right value, which is
            // incremented by the rebuild_tree function
            $right = $this->rebuild_tree($row['id'], $right);
            $result->MoveNext();
        }

      // we've got the left value, and now that we've processed
      // the children of this node we also know the right value
        $sql = "UPDATE " . $this->_table . " SET lft=".$left.", rght=".$right." WHERE id='".$parent."';";
      //echo $sql . "<br>";
        $this->_db->Execute($sql) or die("Error: $sql" . $this->_db->ErrorMsg());

      // return the right value of this node + 1
        return $right+1;
    }


    /*
	*	Call this to add a new node to the tree
	*	@param int $parent id of the node you would like the new node to have as its parent
	*	@param string $name the name of the new node, it will be used to reference its value in the tree array
	*	@param string $value optional value this node is to contain
	*	@param string $aco_spec optional ACO value in section|value format
	*	@return int id of newly added node
	*/
    function add_node($parent_id, $name, $value = "", $aco_spec = "patients|docs")
    {

        $sql = "SELECT * from " . $this->_table . " where parent = '" . $parent_id . "' and name='" . $name . "'";
        $result = $this->_db->Execute($sql) or die("Error: " . $this->_db->ErrorMsg());

        if ($result && !$result->EOF) {
            die("You cannot add a node with the name '" . $name ."' because one already exists under parent " . $parent_id . "<br>");
        }

        $sql = "SELECT * from " . $this->_table . " where id = '" . $parent_id . "'";
        $result = $this->_db->Execute($sql) or die("Error: " . $this->_db->ErrorMsg());

        $next_right = 0;

        if ($result && !$result->EOF) {
            $next_right = $result->fields['rght'];
        }

        $sql = "UPDATE " . $this->_table . " SET rght=rght+2 WHERE rght>=" . $next_right;
        $this->_db->Execute($sql) or die("Error: " . $this->_db->ErrorMsg());
        $sql = "UPDATE " . $this->_table . " SET lft=lft+2 WHERE lft>=" . $next_right;
        $this->_db->Execute($sql) or die("Error: " . $this->_db->ErrorMsg());

        $id = $this->_db->GenID($this->_table . "_seq");
        $sql = "INSERT INTO " . $this->_table . " SET name='" . add_escape_custom($name) .
        "', value='" . add_escape_custom($value) . "', aco_spec='" . add_escape_custom($aco_spec) .
        "', lft='" . $next_right . "', rght='" . ($next_right + 1) .
        "', parent='" . $parent_id . "', id='" . $id . "'";
        $this->_db->Execute($sql) or die("Error: $sql :: " . $this->_db->ErrorMsg());
      //$this->rebuild_tree(1,1);
        $this->load_tree();
        return $id;
    }

    /*
	*	Call this to modify a node's attributes.
	*	@param int $id id of the node to change
	*	@param string $name the new name of the new node
	*	@param string $value optional value this node is to contain
	*	@param string $aco_spec optional ACO value in section|value format
	*	@return int same as input id
	*/
    function edit_node($id, $name, $value = "", $aco_spec = "patients|docs")
    {
        $sql = "SELECT c2.id FROM " . $this->_table . " AS c1, " . $this->_table . " AS c2 WHERE " .
        "c1.id = $id AND c2.id != c1.id AND c2.parent = c1.parent AND c2.name = '" .
        add_escape_custom($name) . "'";
        $result = $this->_db->Execute($sql) or die(xlt('Error') . ": " . $this->_db->ErrorMsg());
        if ($result && !$result->EOF) {
              die(xlt('This name already exists under this parent.') . "<br>");
        }

        $sql = "UPDATE " . $this->_table . " SET name = '" . add_escape_custom($name) .
        "', value = '" . add_escape_custom($value) .
        "', aco_spec = '" . add_escape_custom($aco_spec) . "' WHERE id = $id";
        $this->_db->Execute($sql) or die(xlt('Error') . ": " . $this->_db->ErrorMsg());
        $this->load_tree();
        return $id;
    }

    /*
	*	Call this to delete a node from the tree, the nodes children (and their children, etc) will become children
	*	of the deleted nodes parent
	*	@param int $id id of the node you want to delete
	*/
    function delete_node($id)
    {

        $sql = "SELECT * from " . $this->_table . " where id = '" . $id . "'";
      //echo $sql . "<br>";
        $result = $this->_db->Execute($sql) or die("Error: " . $this->_db->ErrorMsg());

        $left = 0;
        $right = 1;
        $new_parent = 0;

        if ($result && !$result->EOF) {
            $left = $result->fields['lft'];
            $right = $result->fields['rght'];
            $new_parent = $result->fields['parent'];
        }

        $sql = "UPDATE " . $this->_table . " SET rght=rght-2 WHERE rght>" . $right;
      //echo $sql . "<br>";
        $this->_db->Execute($sql) or die("Error: " . $this->_db->ErrorMsg());

        $sql = "UPDATE " . $this->_table . " SET lft=lft-2 WHERE lft>" . $right;
      //echo $sql . "<br>";
        $this->_db->Execute($sql) or die("Error: " . $this->_db->ErrorMsg());

        $sql = "UPDATE " . $this->_table . " SET lft=lft-1, rght=rght-1 WHERE lft>" . $left . " and rght < " . $right;
      //echo $sql . "<br>";
        $this->_db->Execute($sql) or die("Error: " . $this->_db->ErrorMsg());

      //only update the childrens parent setting if the node has children
        if ($right > ($left +1)) {
            $sql = "UPDATE " . $this->_table . " SET parent='" . $new_parent . "' WHERE parent='" . $id . "'";
            //echo $sql . "<br>";
            $this->_db->Execute($sql) or die("Error: " . $this->_db->ErrorMsg());
        }

        $sql = "DELETE FROM " . $this->_table . " where id='" . $id . "'";
      //echo $sql . "<br>";
        $this->_db->Execute($sql) or die("Error: " . $this->_db->ErrorMsg());
        $this->load_tree();

        return true;
    }

    function get_node_info($id)
    {
        if (!empty($this->_id_name[$id])) {
            return $this->_id_name[$id];
        } else {
            return array();
        }
    }

    function get_node_name($id)
    {
        if (!empty($this->_id_name[$id])) {
            return $this->_id_name[$id]['name'];
        } else {
            return false;
        }
    }
}

function array_merge_2(&$array, &$array_i)
{
       // For each element of the array (key => value):
    foreach ($array_i as $k => $v) {
        // If the value itself is an array, the process repeats recursively:
        if (is_array($v)) {
            if (!isset($array[$k])) {
                $array[$k] = array();
            }

            array_merge_2($array[$k], $v);

           // Else, the value is assigned to the current element of the resulting array:
        } else {
            if (isset($array[$k]) && is_array($array[$k])) {
                $array[$k][0] = $v;
            } else {
                if (isset($array) && !is_array($array)) {
                    $temp = $array;
                    $array = array();
                    $array[0] = $temp;
                }

                $array[$k] = $v;
            }
        }
    }
}


function array_merge_n()
{
    // Initialization of the resulting array:
    $array = array();

    // Arrays to be merged (function's arguments):
    $arrays =& func_get_args();

    // Merging of each array with the resulting one:
    foreach ($arrays as $array_i) {
        if (is_array($array_i)) {
            array_merge_2($array, $array_i);
        }
    }

    return $array;
}


/*
$db = $GLOBALS['adodb']['db'];
$sql = "USE document;";
$db->Execute($sql);
$t = new Tree(1);
echo "<pre>";
print_r($t->tree);
//$nid = $t->add_node(0,"test node2","test value");
//$t->add_node($nid,"test child","another value");
//$t->add_node($nid,"test child");
print_r($t->tree);
echo "</pre>";
*/
