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
Purpose: Makes categories required for weblogs.
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
	var $version		= '1.0.4';
	var $description	= 'Makes categories required for selected weblogs.';
	var $settings_exist	= 'n';
	var $docs_url		= 'http://www.designchuchi.ch/index.php/blog/comments/required-category-extension/';

	// --------------------------------
	//  Settings Variables
	// --------------------------------
	var $require_cat 	= FALSE;
	var $cat_limit 		= 0;
	var $exact_cat      = FALSE;
	
	// Internal magic constants
	var $limit_total 	= 30;
	var $debug          = FALSE;

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
		$sql[] = "CREATE TABLE `exp_dc_required_cat` (`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY, `weblog_id` INT NOT NULL, `require_cat` INT NOT NULL DEFAULT '0', `cat_limit` INT NOT NULL DEFAULT '0', `exact_cat` INT NOT NULL DEFAULT '0')";
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
		// Add single_cat limit column
		if ($current < '1.0.3')
		{
			$sql[] = "ALTER TABLE `exp_dc_required_cat` ADD `single_cat` INT NOT NULL DEFAULT '0'";
		}
		
		// Rename limit column, added in version 1.0.4
		if ($current < '1.0.4')
		{
			$sql[] = "ALTER TABLE `exp_dc_required_cat` CHANGE `single_cat` `cat_limit` INT NOT NULL DEFAULT '0'";
			$sql[] = "ALTER TABLE `exp_dc_required_cat` ADD `exact_cat` INT NOT NULL DEFAULT '0'";
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
		$DB->query("DELETE FROM exp_extensions WHERE class = '" . get_class($this) . "'");
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
		global $EE, $EXT;
		
		if ($EE === NULL)
		{
			return;
		}

		// Get weblog settings and check for errors in this post
		$errors = $this->_check_errors($_POST['weblog_id']);

		// spit out any errors
		if (count($errors) > 0)
		{
			$EE->new_entry_form('preview', '<ul><li>'.implode('</li><li>',array_filter($errors)).'</li></ul>');
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
		global $OUT;
		
		// Get weblog settings and check for errors in this post
		$errors = $this->_check_errors($_POST['weblog_id']);

		// spit out any errors
		if (count($errors) > 0)
		{
			return $OUT->show_user_error('general', '<ul><li>'.implode('</li><li>',array_filter($errors)).'</li></ul>');
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

		//	check if someone else uses this
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

		//	now we can fetch the language file
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

		//	requires a category? settings
		$r .= $DSP->tr();
		$r .= $DSP->table_qcell('tableCellOne', $DSP->qspan('defaultBold', $LANG->line('pref_categories')), '50%');
		$r .= $DSP->table_qcell('tableCellOne',
				$DSP->input_radio('dc_required_category', '1', $this->require_cat ? 1 : 0).$LANG->line('radio_yes').NBS.
				$DSP->input_radio('dc_required_category', '0', !$this->require_cat ? 1 : 0).$LANG->line('radio_no'), '50%');
		$r .= $DSP->tr_c();
		
		//	category limit settings: Options
		$options = 	$DSP->input_select_header('dc_category_limit', '', 1);
		
		for ($i = 0; $i <= $this->limit_total; $i++)
		{
			$selected = ($this->cat_limit == $i) ? TRUE : FALSE;
			
			//	First value is a special string
			$value = ($i == 0) ? $LANG->line('pref_no_limit') : $i;
			$options .= $DSP->input_select_option($i, $value, $selected);
		}
		
		$options .= $DSP->input_select_footer();

		$r .= $DSP->tr();
		$r .= $DSP->table_qcell('tableCellTwo', $DSP->qspan('defaultBold', $LANG->line('pref_category_limit')), '50%');
		$r .= $DSP->table_qcell('tableCellTwo',	$options, '50%');
		$r .= $DSP->tr_c();
		
		//	category number has to be exact?
		$r .= $DSP->tr();
		$r .= $DSP->table_qcell('tableCellOne', $DSP->qspan('defaultBold', $LANG->line('pref_category_exact')), '50%');
		$r .= $DSP->table_qcell('tableCellOne',
				$DSP->input_radio('dc_exact_category', '1', $this->exact_cat ? 1 : 0).$LANG->line('radio_yes').NBS.
				$DSP->input_radio('dc_exact_category', '0', !$this->exact_cat ? 1 : 0).$LANG->line('radio_no'), '50%');
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

		if (isset($_POST['weblog_id']) && isset($_POST['dc_required_category']) && isset($_POST['dc_category_limit']) && isset($_POST['dc_exact_category']))
		{
			// insert new values or update existing ones
			$DB->query("INSERT INTO exp_dc_required_cat VALUES('', '".$DB->escape_str($_POST['weblog_id'])."', '".$DB->escape_str($_POST['dc_required_category'])."', '".$DB->escape_str($_POST['dc_category_limit'])."', '".$DB->escape_str($_POST['dc_exact_category'])."') ON DUPLICATE KEY UPDATE `weblog_id`=values(`weblog_id`), `require_cat`=values(`require_cat`), `cat_limit`=values(`cat_limit`), `exact_cat`=values(`exact_cat`)");

			// unset so we don't get any errors on "update" on the admin page
			unset($_POST['dc_required_category']);
			unset($_POST['dc_category_limit']);
			unset($_POST['dc_exact_category']);
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

		//	=============================================
		//	Set settings variables
		//	=============================================

		//	set require category value
		$this->require_cat = ($preferences->row['require_cat'] == 1) ? TRUE : FALSE;
		
		//	set category limit value
		$this->cat_limit = $preferences->row['cat_limit'];
		
		//	set exact category value
		$this->exact_cat = ($preferences->row['exact_cat'] == 1) ? TRUE : FALSE;

		if ($this->debug)
		{
			echo("<pre>\n");
			print_r($this);
			echo("</pre>\n");
		}
	}
	
	/**
	 * Checks whether there's a category limit set.
	 *
	 * @since	Version 1.0.4
	 */
	function _has_category_limit() {
		return $this->cat_limit > 0;
	}
	
	/**
	 * Checks for errors in a weblog post depending on the
	 * settings for the given weblog and on the weblog_id passed
	 * to this function. Settings for a weblog are
	 * populated in this function.
	 *
	 * @param   string	$weblog_id	A weblog id.
	 * @since	Version 1.0.4
	 * @return  array   $errors     An array containing errors.
	 */
	function _check_errors($weblog_id) {
		global $LANG;

		$LANG->fetch_language_file('dc_required_category');

		//	=============================================
		//	Set weblog preferences
		//	=============================================
		//  Only one query for all settings
		$this->_set_preferences($weblog_id);

		// error array
		$errors = array();

		// category required
		if ($this->require_cat && empty($_POST['category']))
		{
		    $errors[] = $LANG->line('error_empty');
		}
		// check limits
		if ($this->_has_category_limit())
		{
			if (@sizeof($_POST['category']) > $this->cat_limit)
			{
				$errors[] = ($this->cat_limit == 1) ? $LANG->line('error_cat_single') : str_replace('%{limit}', $this->cat_limit, $LANG->line('error_cat_limit'));
			}
			// check limit exact
			if ($this->exact_cat && @sizeof($_POST['category']) != $this->cat_limit)
			{
				$errors[] = ($this->cat_limit == 1) ? $LANG->line('error_cat_exact_single') : str_replace('%{limit}', $this->cat_limit, $LANG->line('error_cat_exact'));
			}
		}
		
		return $errors;
	}
}
//END CLASS
?>
