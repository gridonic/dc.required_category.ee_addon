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
	var $version		= '1.0.3';
	var $description	= 'Makes categories required for selected weblogs.';
	var $settings_exist	= 'n';
	var $docs_url		= 'http://www.designchuchi.ch/index.php/blog/comments/required-category-extension/';

	// --------------------------------
	//  Settings Variables
	// --------------------------------
	var $require_cat = FALSE;
	var $single_cat = FALSE;

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
			'sessions_start'					=> 'save_weblog_settings',
			'submit_new_entry_start'			=> 'check_post_for_category',
			'show_full_control_panel_end'		=> 'edit_weblog_prefs',
			'weblog_standalone_insert_entry'  	=> 'check_saef_for_category'
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
		$sql[] = "CREATE TABLE `exp_dc_required_cat` (`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY, `weblog_id` INT NOT NULL, `require_cat` INT NOT NULL DEFAULT '0', `single_cat` INT NOT NULL DEFAULT '0')";
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

		//	=============================================
		//	Is Current?
		//	=============================================
		if ($current == '' OR $current == $this->version)
		{
			return FALSE;
		}
		
		//	=============================================
		//	Update?
		//	=============================================
		if($current < '1.0.3')
		{
			$sql[] = "ALTER TABLE `exp_dc_required_cat` ADD `single_cat` INT NOT NULL DEFAULT '0'";
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
		
		//	=============================================
		//	Set weblog preferences
		//	=============================================
		//  Only one query for all settings
		$this->_set_preferences($_POST['weblog_id']);
		
		if($this->require_cat && empty($_POST['category']))
		{
			$EE->new_entry_form('preview', $LANG->line('error_empty'));
			$EXT->end_script = TRUE;
		}
		else if($this->single_cat && sizeof($_POST['category']) > 1)
		{
			$EE->new_entry_form('preview', $LANG->line('error_single_cat'));
		    $EXT->end_script = TRUE;
		}
		
		// If no errors, just get out of here...
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
		
		//	=============================================
		//	Set weblog preferences
		//	=============================================
		//  Only one query for all settings
		$this->_set_preferences($_POST['weblog_id']);
		
		// we have the right weblog which also has cat group assigned
		if($this->require_cat && empty($_POST['category']))
		{
			return $OUT->show_user_error('general', $LANG->line('error_empty'));
		}
		else if($this->single_cat && sizeof($_POST['category']) > 1)
		{
			return $OUT->show_user_error('general', $LANG->line('error_single_cat'));
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

		// Requires a category? settings
		$r .= $DSP->tr();
		$r .= $DSP->table_qcell('tableCellOne', $DSP->qspan('defaultBold', $LANG->line('pref_categories')), '50%');
		$r .= $DSP->table_qcell('tableCellOne',
				$DSP->input_radio('dc_required_category', '1', $this->require_cat ? 1 : 0).$LANG->line('radio_yes').NBS.
				$DSP->input_radio('dc_required_category', '0', !$this->require_cat ? 1 : 0).$LANG->line('radio_no'), '50%');
		$r .= $DSP->tr_c();
		
		// Only one category settings
		$r .= $DSP->tr();
		$r .= $DSP->table_qcell('tableCellTwo', $DSP->qspan('defaultBold', $LANG->line('pref_category_single')), '50%');
		$r .= $DSP->table_qcell('tableCellTwo',
				$DSP->input_radio('dc_single_category', '1', $this->single_cat ? 1 : 0).$LANG->line('radio_yes').NBS.
				$DSP->input_radio('dc_single_category', '0', !$this->single_cat ? 1 : 0).$LANG->line('radio_no'), '50%');
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

		if (isset($_POST['weblog_id']) && isset($_POST['dc_required_category']) && isset($_POST['dc_single_category']))
		{
			// insert new values or update existing ones
			$DB->query("INSERT INTO exp_dc_required_cat VALUES('', '".$DB->escape_str($_POST['weblog_id'])."', '".$DB->escape_str($_POST['dc_required_category'])."', '".$DB->escape_str($_POST['dc_single_category'])."') ON DUPLICATE KEY UPDATE `weblog_id`=values(`weblog_id`), `require_cat`=values(`require_cat`), `single_cat`=values(`single_cat`)");

			// unset so we don't get any errors on "update" on the admin page
			unset($_POST['dc_required_category']);
			unset($_POST['dc_single_category']);
		}
	}


	//  ========================================================================
	//  Private Functions
	//  ========================================================================
	
	/**
	 * Sets internal preferences for a given weblog.
	 *
 	 * @param   string $weblog_id A weblog id.
 	 * @since	Version 1.0.0
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
		
		// set category limit value
		$this->single_cat = ($preferences->row['single_cat'] == 1) ? TRUE : FALSE;
	}
}
//END CLASS
?>