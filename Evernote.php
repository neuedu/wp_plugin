<?php
/*
  Plugin Name: ePals Evernote
  Plugin URI: http://www.epals.com/
  Description: Declares a plugin about Evernote
  Version: 1.0
  Author: Caroline Sun
  Author URI: http://www.epals.com/
  License: GPLv2
 */

require_once(dirname(__FILE__) . "/vendor/autoload.php");

use ePals\EvernoteHandler;
use EDAM\Types\Data,
    EDAM\Types\Note,
    EDAM\Types\Notebook,
    EDAM\Types\Resource,
    EDAM\Types\ResourceAttributes,
    EDAM\NoteStore;
use EDAM\Error\EDAMUserException,
    EDAM\Error\EDAMErrorCode;
use Evernote\Client;

add_action('init', 'create_EvernotePost');

function create_EvernotePost() {
    register_post_type('epals_evernote', array(
        'labels' => array(
            'name' => 'ePals Evernotes',
            'singular_name' => 'ePals Evernote',
            'add_new' => 'Add ePals Evernote',
            'add_new_item' => 'Add ePals Evernote',
            'edit' => 'Edit',
            'edit_item' => 'Edit ePals Evernote',
            'new_item' => 'New ePals Evernote',
            'view' => 'View',
            'view_item' => 'View ePals Evernote',
            'search_items' => 'Search ePals Evernote',
            'not_found' => 'No ePals Evernote found',
            'not_found_in_trash' => 'No ePals Evernote found in Trash',
            'parent' => 'Parent Evernote'
        ),
        'public' => true,
        'menu_position' => 15,
        'taxonomies' => array(''),
        'has_archive' => true
            )
    );
}

// after edit
add_action('admin_init', 'ePalsEvernote_meta_admin');

function ePalsEvernote_meta_admin() {
    add_meta_box('epals_evernote_meta_box', 'ePals Evernote Details', 'after_epals_evernote', 'epals_evernote', 'normal', 'high'
    );
}

function after_epals_evernote($epals_evernote) {
    $post_id = $epals_evernote->ID;
    $NoteGuid = esc_html(get_post_meta($post_id, 'NoteGuid', true));
    $NoteBookGuid = esc_html(get_post_meta($post_id, 'NotebookGuid', true));
    $updateSequenceNum = esc_html(get_post_meta($post_id, 'updateSequenceNum', true));
    // test data
    $notebooklist = wp_cache_get('evernoteBookList');
    if ($notebooklist == FALSE || !isset($notebooklist)) {
        $config = parse_ini_file('api.ini');
        $USER_TOKEN = $config['user_token'];
        $SANDBOX = $config['sandbox'];
        $OAUTH_CONSUMER_KEY = $config['oauth_consumer_key'];
        $OAUTH_CONSUMER_SECRET = $config['oauth_consumer_secret'];
        // evernote notebook list
        $handle = new EvernoteHandler($USER_TOKEN, $SANDBOX, $OAUTH_CONSUMER_KEY, $OAUTH_CONSUMER_SECRET); //show notebooks
        $notebooklist = $handle->queryNotebook();
        wp_cache_add('evernoteBookList', $notebooklist);
    }
    ?>
    <table>
        <tr>
            <td style="width: 100%;">Notebook</td>
            <td>
                <select name="epalsevernote_notebook">
                    <?php
                    foreach ($notebooklist as $notebook) {
                        ?>
                        <option value="<?php echo $notebook->guid; ?>" <?php if ($notebook->guid == $NoteBookGuid) { ?> selected="selected" <?php } ?> ><?php echo $notebook->name; ?></option>
                    <?php } ?>
                </select>
            </td>
        </tr>
        <tr>
            <td style="width: 100%"> Evernote ID</td>
            <td><input type="text" readonly size="80" name="epalsevernote_noteguid" value="<?php echo $NoteGuid; ?>" /></td>
        </tr>
        <tr>
            <td style="width: 100%"> Note Version</td>
            <td><input type="text" readonly size="10" name="epalsevernote_updateSequenceNum" value="<?php echo $updateSequenceNum; ?>" /></td>
        </tr>
    </table>
    <?php
}

// before edit
add_action('add_meta_boxes', 'before_epals_evernote', 10, 2);

function before_epals_evernote($post_type, $epals_evernote) {
    if ($post_type == 'epals_evernote') {
        // this way is bad
        $post_id = $epals_evernote->ID;
        $NoteGuid = esc_html(get_post_meta($post_id, 'NoteGuid', true));
        $NoteBookGuid = esc_html(get_post_meta($post_id, 'NotebookGuid', true));
        $updateSequenceNum = esc_html(get_post_meta($post_id, 'updateSequenceNum', true));

        /**
         * synchronous evernote
         */
        if (($epals_evernote->post_status == 'publish') && isset($NoteGuid)) {
            $config = parse_ini_file('api.ini');
            $USER_TOKEN = $config['user_token'];
            $SANDBOX = $config['sandbox'];
            $OAUTH_CONSUMER_KEY = $config['oauth_consumer_key'];
            $OAUTH_CONSUMER_SECRET = $config['oauth_consumer_secret'];
            // evernote notebook list
            $handle = new EvernoteHandler($USER_TOKEN, $SANDBOX, $OAUTH_CONSUMER_KEY, $OAUTH_CONSUMER_SECRET); //show notebooks
            // epals evernote modified time
            $note = $handle->getNote($NoteGuid);
            $evernoteUpdateNum = $note->updateSequenceNum;
            // update evernote
            if ($evernoteUpdateNum > $updateSequenceNum) {
                // update version
                update_post_meta($post_id, 'updateSequenceNum', $evernoteUpdateNum);
                $evernoteNotebookGuid = $note->notebookGuid;
                if ($evernoteNotebookGuid != $NoteBookGuid) {
                    update_post_meta($post_id, 'NotebookGuid', $evernoteNotebookGuid);
                }
                // updated
                $title = $note->title;
                $content = $handle->getNoteContent($NoteGuid);
                preg_match_all('/<en-note>(.+)?<\/en-note>/', $content, $rs);

                $contentBody = $rs[1][0];

                $newEvernote = array(
                    'ID' => $post_id,
                    'post_title' => $title,
                    'post_content' => $contentBody
                );
                wp_update_post($newEvernote);
                wp_cache_delete('evernoteBookList');
                //$updateSequenceNum = $evernoteUpdateNum;
            }
        }
    }
}

add_action('save_post', 'add_epals_evernote_fields', 10, 2);

function add_epals_evernote_fields($epals_evernote_id, $epals_evernote) {

    // Check post type for epals_evernote, the weird thing is in the backend the post type is stored as "epalsevernotes"
    if ($epals_evernote->post_type == 'epals_evernote') {

        // Store data in post meta table if present in post data
        if (isset($_POST['epalsevernote_notebook']) && $_POST['epalsevernote_notebook'] != '') {
            update_post_meta($epals_evernote_id, 'NotebookGuid', $_POST['epalsevernote_notebook']);
        }
    }
}

add_action('draft_to_publish', 'publish_epals_evernote_fields');

function publish_epals_evernote_fields($epals_evernote) {

    // Check post type for epals_evernote, the weird thing is in the backend the post type is stored as "epalsevernotes"
    if ($epals_evernote->post_type == 'epals_evernote') {

        $parentNotebook = new Notebook();

        // Store data in post meta table if present in post data
        if (isset($_POST['epalsevernote_notebook']) && $_POST['epalsevernote_notebook'] != '') {
            update_post_meta($epals_evernote->ID, 'NotebookGuid', $_POST['epalsevernote_notebook']);
            $parentNotebook->guid = $_POST['epalsevernote_notebook'];
        } else {
            $meta_guid = get_post_meta($epals_evernote->ID, 'NotebookGuid', true);
            $parentNotebook->guid = $meta_guid;
        }
        $noteGuid = '';
        if (isset($_POST['epalsevernote_noteguid']) && $_POST['epalsevernote_noteguid'] != '') {
            $noteGuid = $_POST['epalsevernote_noteguid'];
        }
        $updateSequenceNum;
        if (isset($_POST['epalsevernote_updateSequenceNum']) && $_POST['epalsevernote_updateSequenceNum'] != '') {
            $noteGuid = $_POST['epalsevernote_updateSequenceNum'];
        }

        // Add the Evernote into evernote sendbox Store using API
        $config = parse_ini_file('api.ini');
        $USER_TOKEN = $config['user_token'];
        $SANDBOX = $config['sandbox'];
        $OAUTH_CONSUMER_KEY = $config['oauth_consumer_key'];
        $OAUTH_CONSUMER_SECRET = $config['oauth_consumer_secret'];
        $handle = new EvernoteHandler($USER_TOKEN, $SANDBOX, $OAUTH_CONSUMER_KEY, $OAUTH_CONSUMER_SECRET);
        if (empty($noteGuid) && $epals_evernote->post_title !== "Auto Draft") {
            // create
            $newNote = $handle->makeNote($epals_evernote->post_title, $epals_evernote->post_content, $parentNotebook);
            $noteGuid = $newNote->guid;
            $updateSequenceNum = $newNote->updateSequenceNum;
            update_post_meta($epals_evernote->ID, 'NoteGuid', $noteGuid);
            update_post_meta($epals_evernote->ID, 'updateSequenceNum', $updateSequenceNum);
        } else if (!empty($noteGuid)) {
            // update
            $note = $handle->updateNote($epals_evernote->post_title, $epals_evernote->post_content, $parentNotebook, $noteGuid);
            $updateSequenceNum = $note->updateSequenceNum;
            update_post_meta($epals_evernote->ID, 'updateSequenceNum', $updateSequenceNum);
        }
    }
}

// update evernote
add_action('publish_to_publish', 'publish_update_epals_evernote_fields');

function publish_update_epals_evernote_fields($epals_evernote) {

    // Check post type for epals_evernote, the weird thing is in the backend the post type is stored as "epalsevernotes"
    if ($epals_evernote->post_type == 'epals_evernote') {
        $post_id = $epals_evernote->ID;

        $parentNotebook = new Notebook();

        // Store data in post meta table if present in post data
        if (isset($_POST['epalsevernote_notebook']) && $_POST['epalsevernote_notebook'] != '') {
            update_post_meta($post_id, 'NotebookGuid', $_POST['epalsevernote_notebook']);
            $parentNotebook->guid = $_POST['epalsevernote_notebook'];
        } else {
            $meta_guid = get_post_meta($post_id, 'NotebookGuid', true);
            $parentNotebook->guid = $meta_guid;
        }
        $noteGuid = '';
        if (isset($_POST['epalsevernote_noteguid']) && $_POST['epalsevernote_noteguid'] != '') {
            $noteGuid = $_POST['epalsevernote_noteguid'];
        } else {
            $noteGuid = get_post_meta($post_id, 'NoteGuid', true);
        }
        $updateSequenceNum;
        if (isset($_POST['epalsevernote_updateSequenceNum']) && $_POST['epalsevernote_updateSequenceNum'] != '') {
            $noteGuid = $_POST['epalsevernote_updateSequenceNum'];
        } else {
            $updateSequenceNum = get_post_meta($post_id, 'updateSequenceNum', true);
        }

        // Add the Evernote into evernote sendbox Store using API
        $config = parse_ini_file('api.ini');
        $USER_TOKEN = $config['user_token'];
        $SANDBOX = $config['sandbox'];
        $OAUTH_CONSUMER_KEY = $config['oauth_consumer_key'];
        $OAUTH_CONSUMER_SECRET = $config['oauth_consumer_secret'];
        $handle = new EvernoteHandler($USER_TOKEN, $SANDBOX, $OAUTH_CONSUMER_KEY, $OAUTH_CONSUMER_SECRET);
        $oldNote = $handle->getNote($noteGuid);
        $evernoteUpdateNum = $oldNote->updateSequenceNum;
        // noteGuid exits and not update
        if (!empty($noteGuid) && ($evernoteUpdateNum != $updateSequenceNum)) {
            // update
            $note = $handle->updateNote($epals_evernote->post_title, $epals_evernote->post_content, $parentNotebook, $noteGuid);
            $updateSequenceNum = $note->updateSequenceNum;
            update_post_meta($post_id, 'updateSequenceNum', $updateSequenceNum);
        }
    }
}

// delete evernote
add_action('publish_to_trash', 'trash_epals_evernote_fields');

function trash_epals_evernote_fields($epals_evernote) {

    // Check post type for epals_evernote, the weird thing is in the backend the post type is stored as "epalsevernotes"
    if ($epals_evernote->post_type == 'epals_evernote') {

        $noteGuid = get_post_meta($epals_evernote->ID, 'NoteGuid', true);
        if (!empty($noteGuid)) {
            // Add the Evernote into evernote sendbox Store using API
            $config = parse_ini_file('api.ini');
            $USER_TOKEN = $config['user_token'];
            $SANDBOX = $config['sandbox'];
            $OAUTH_CONSUMER_KEY = $config['oauth_consumer_key'];
            $OAUTH_CONSUMER_SECRET = $config['oauth_consumer_secret'];
            $handle = new EvernoteHandler($USER_TOKEN, $SANDBOX, $OAUTH_CONSUMER_KEY, $OAUTH_CONSUMER_SECRET);
            $note = $handle->deleteNote($noteGuid);
            $updateSequenceNum = $note->updateSequenceNum;
            update_post_meta($epals_evernote->ID, 'updateSequenceNum', $updateSequenceNum);
        }
    }
}
