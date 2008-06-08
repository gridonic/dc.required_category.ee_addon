<?php
//SVN $Id$

/*
=====================================================
DC Required Category
-----------------------------------------------------
http://www.designchuchi.ch/
-----------------------------------------------------
Copyright (c) 2008 - today Designchuchi
=====================================================
THIS MODULE IS PROVIDED "AS IS" WITHOUT WARRANTY OF
ANY KIND OR NATURE, EITHER EXPRESSED OR IMPLIED,
INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES
OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE,
OR NON-INFRINGEMENT.
=====================================================
File: ext.dc_required_category.php
-----------------------------------------------------
Purpose: Makes selected category groups required.
=====================================================
*/

if (!defined('EXT'))
{
	exit('Invalid file request');
}

class DC_Required_Category
{

	var $settings		= array();

	var $name			= 'Required Category Extension';
	var $version		= '1.0.2';
	var $description	= 'Makes categories required for selected weblogs.';
	var $settings_exist	= 'n';
	var $docs_url		= 'http://www.designchuchi.ch/index.php/blog/comments/required-category-extension/';

	// --------------------------------
	//  Settings Variables
	// --------------------------------
	var $require_cat = FALSE;

	// -------------------------------
	//  Constructor - Extensions use this for settings
	// -------------------------------
	function DC_Required_Category($settings='')
	{
		$this->settings = $settings;
	}

	// --------------------------------
	//  Activate Extension
	// --------------------------------

	function activate_extension()
	{
		global $DB;

		// hooks array
		$hooks = array(
			'sessions_start'				=> 'save_weblog_settings',
			'submit_new_entry_start'		=> 'check_post_for_category',
			'show_full_control_panel_end'	=> 'edit_weblog_prefs',
			'weblog_standalone_insert_entry'  => 'check_saef_for_category'
		);

		foreach ($hooks as $hook => $method)
		{
			$sql[] = $DB->insert_string( 'exp_extensions',
				array(
					'extension_id'	=> '',
					'class'			=> get_class($this),
					'method'		=> $method,
					'hook'			=> $hook,
					'settings'		=> '',
					'priority'		=> 10,
					'version'		=> $this->version,
					'enabled'		=> 'y'
				)
			);
		}

		// add extension table
		$sql[] = "CREATE TABLE `exp_dc_required_cat` (`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY, `weblog_id` INT NOT NULL, `require_cat` INT NOT NULL DEFAULT '0')";
		$sql[] = 'ALTER TABLE `exp_dc_required_cat` ADD UNIQUE `WEBLOG_ID` ( `weblog_id` )';

		// run all sql queries
		foreach ($sql as $query)
		{
			$DB->query($query);
		}

		return TRUE;
	}

	// --------------------------------
	//  Update Extension
	// --------------------------------
	function update_extension($current = '')
	{
		global $DB;

		if ($current == '' OR $current == $this->version)
		{
			return FALSE;
		}

		$sql[] = "UPDATE exp_extensions SET version = '" . $DB->escape_str($this->version) . "' WHERE class = '" . get_class($this) . "'";

		// run all sql queries
		foreach ($sql as $query)
		{
			$DB->query($query);
		}
	}

	// --------------------------------
	//  Disable Extension
	// --------------------------------
	function disable_extension()
	{
		global $DB;
		$DB->query("DELETE FROM exp_extensions WHERE class = '".get_class($this)."'");
		$DB->query('DROP TABLE IF EXISTS `exp_dc_required_cat`');
	}

	/**
	 * Checks whether the category is required during posting
	 * or editing a weblog content.
	 *
	 * @see		submit_new_entry_start hook
	 * @since	Version 1.0.0
	 */
	function check_post_for_category() {

		global $EE, $EXT, $LANG;
		$LANG->fetch_language_file('dc_required_category');
		
        if ($EE === NULL)
        {
            return;
		}
		// we have the right weblog which also has cat group assigned
		if ($this->_requires_category($_POST['weblog_id']) && empty($_POST['category']))
		{
			$EE->new_entry_form('preview', $LANG->line('error_empty'));
			$EXT->end_script = TRUE;
		}

		return;
	}
	
	/**
	 * Checks whether the category is required during posting
	 * or editing a weblog content through SAEF.
	 *
	 * @see		weblog_standalone_insert_entry hook
	 * @since	Version 1.0.2
	 */
	function check_saef_for_category() {
		global $LANG, $OUT;
		
		$LANG->fetch_language_file('dc_required_category');

		// we have the right weblog which also has cat group assigned
		if ($this->_requires_category($_POST['weblog_id']) && empty($_POST['category']))
		{
            return $OUT->show_user_error('general', $LANG->line('error_empty'));
		}
	}

	/**
	 * Modifies control panel html by adding the required category
	 * settings panel to Admin > Weblog Administration > Weblog Management > Edit Weblog
	 *
	 * @param	string $out The control panel html
	 * @return	string The modified control panel html
	 * @see		show_full_control_panel_end hook
	 * @since	Version 1.0.0
	 */
	function edit_weblog_prefs($out) {

		global $DB, $EXT, $IN, $DSP, $LANG;

		// check if someone else uses this
		if ($EXT->last_call !== FALSE)
		{
			$out = $EXT->last_call;
		}

		//	=============================================
		//	Only Alter Weblog Preferences (on update too!)
		//	=============================================
		if($IN->GBL('M') != 'blog_admin' || ($IN->GBL('P') != 'blog_prefs' && $IN->GBL('P') !=  'update_preferences'))
		{
			return $out;
		}

		// now we can fetch the language file
		$LANG->fetch_language_file('dc_required_category');

		//	=============================================
		//	Set preferences from DB based on weblog id
		//	=============================================
		$weblog_id = isset($_POST['weblog_id']) ? $_POST['weblog_id'] : $IN->GBL('weblog_id');
		if (!is_numeric($weblog_id))
		{
			$weblog_id = FALSE;
		}

		$this->_set_preferences($weblog_id);

		//	=============================================
		//	Find Table
		//	=============================================
		preg_match('/name=[\'"]blog_title[\'"].*?<\/table>/si', $out, $table);

		//	=============================================
		//	Create Fields
		//	=============================================
		$r = $DSP->br();

		$r .= $DSP->table('tableBorder', '0', '', '100%');
		$r .= $DSP->tr();
		$r .= '<td class="tableHeadingAlt" colspan="2" align="left">'.NBS.$LANG->line('heading_preferences').$DSP->td_c();
		$r .= $DSP->tr_c();

		$r .= $DSP->tr();
		$r .= $DSP->table_qcell('tableCellOne', $DSP->qspan('defaultBold', $LANG->line('pref_categories')), '50%');
		$r .= $DSP->table_qcell('tableCellOne',
				$DSP->input_radio('dc_required_category', '1', $this->require_cat ? 1 : 0).$LANG->line('radio_yes').NBS.
				$DSP->input_radio('dc_required_category', '0', !$this->require_cat ? 1 : 0).$LANG->line('radio_no'), '50%');
		$r .= $DSP->tr_c();

		$r.= $DSP->table_c();

		//	=============================================
		//	Add Fields
		//	=============================================
		$out = @str_replace($table[0], $table[0].$r, $out);

		return $out;
	}

	/**
	 * Saves the required category preferences.
	 *
	 * @see		sessions_start hook
	 * @since	Version 1.0.0
	 */
	function save_weblog_settings() {

	global $DB;

		if (isset($_POST['weblog_id']) && isset($_POST['dc_required_category']))
		{
			// insert new values or update existing ones
			$DB->query("INSERT INTO exp_dc_required_cat VALUES('', '".$DB->escape_str($_POST['weblog_id'])."', '".$DB->escape_str($_POST['dc_required_category'])."') ON DUPLICATE KEY UPDATE `weblog_id`=values(`weblog_id`), require_cat=values(`require_cat`)");

			// unset so we don't get any errors on "update" on the admin page
			unset($_POST['dc_required_category']);
		}
	}


	//  ========================================================================
	//  Private Functions
	//  ========================================================================
	
	/**
	 * Sets internal preferences for a given weblog.
	 *
 	 * @param   string $weblog_id A weblog id.
	 */
	function _set_preferences($weblog_id) {
		global $DB;

		$preferences = $DB->query("SELECT * FROM exp_dc_required_cat WHERE weblog_id='".$DB->escape_str($weblog_id)."'");

		if ($preferences->num_rows != 1)
		{
			return;
		}

		// set require category value
		$this->require_cat = ($preferences->row['require_cat'] == 1) ? TRUE : FALSE;
	}
	
	/**
	 * Checks whether a weblog requires at least one category.
	 *
	 * @param   string $weblog_id A weblog id.
	 * @return  boolean True if a weblog requires at least one category, false else.
	 */
	function _requires_category($weblog_id) {
	    global $DB;
	    
   		// check if we have a weblog with a category group and if required category is set
		$query = $DB->query("SELECT b.weblog_id, b.cat_group FROM exp_weblogs AS b INNER JOIN exp_dc_required_cat AS d ON b.weblog_id = d.weblog_id WHERE b.cat_group != '' AND d.weblog_id = '" . $DB->escape_str($weblog_id) . "' AND d.require_cat = '1'");
		
		return $query->num_rows > 0;
	}

}
//END CLASS
?>