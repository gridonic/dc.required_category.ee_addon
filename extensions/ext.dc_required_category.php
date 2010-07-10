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

if (!defined('EXT')) { exit('Invalid file request'); }

// define constants
if (!defined('DC_REQ_CAT_VERSION'))
{
	define("DC_REQ_CAT_VERSION",	'1.0.6');
	define("DC_REQ_CAT_ID",			'DC Required Category');
	define("DC_REQ_CAT_DOCS",		'http://www.designchuchi.ch/index.php/blog/comments/required-category-extension/');
}

/**
 * Makes categories required for weblogs.
 *
 * @version		1.0.5
 * @author		{@link http://designchuchi.ch} Designchuchi
 * @see			http://www.designchuchi.ch/index.php/blog/comments/required-category-extension/
 * @copyright	Copyright (c) 2008-2009 Designchuchi
*/
class DC_Required_Category
{

	var $settings		= array();

	var $name			= 'DC Required Category';
	var $version		= DC_REQ_CAT_VERSION;
	var $description	= 'Makes categories required for selected weblogs.';
	var $settings_exist = 'y';
	var $docs_url		= DC_REQ_CAT_DOCS;

	// --------------------------------
	//	Settings Variables
	// --------------------------------
	var $require_cat	= FALSE;
	var $cat_limit		= 0;
	var $exact_cat		= FALSE;
	var $count_parents	= TRUE;

	// Internal magic constants
	var $limit_total	= 30;
	var $debug			= FALSE;

	// -------------------------------
	//	Constructor - Extensions use this for settings
	// -------------------------------
	function DC_Required_Category($settings='')
	{
		$this->settings = $this->_get_site_settings($settings);
	}

	// --------------------------------
	//	Activate Extension
	// --------------------------------

	function activate_extension()
	{
		global $DB;

		// hooks array
		$hooks = array(
			'sessions_start'					=> 'save_weblog_settings',
			'submit_new_entry_start'			=> 'check_post_for_category',
			'show_full_control_panel_end'		=> 'edit_weblog_prefs',
			'weblog_standalone_insert_entry'	=> 'check_saef_for_category',
			/* Lg Addon Updater Hooks */
			'lg_addon_update_register_source'	 => 'dc_required_category_register_source',
			'lg_addon_update_register_addon'	 => 'dc_required_category_register_addon'
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
		$sql[] = "CREATE TABLE `exp_dc_required_cat` (
			`id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
			`weblog_id` INT NOT NULL,
			`require_cat` INT NOT NULL DEFAULT '0',
			`cat_limit` INT NOT NULL DEFAULT '0',
			`exact_cat` INT NOT NULL DEFAULT '0',
			`count_parents` INT NOT NULL DEFAULT '1')";

		$sql[] = 'ALTER TABLE `exp_dc_required_cat` ADD UNIQUE `WEBLOG_ID` ( `weblog_id` )';

		// run all sql queries
		foreach ($sql as $query)
		{
			$DB->query($query);
		}

		return TRUE;
	}

	// --------------------------------
	//	Update Extension
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

		// LG Addon Updater hooks, added in version 1.0.5
		if ($current < '1.0.5')
		{
			// hooks array
			$hooks = array(
				'lg_addon_update_register_source'	=> 'dc_required_category_register_source',
				'lg_addon_update_register_addon'	=> 'dc_required_category_register_addon'
			);

			foreach ($hooks as $hook => $method)
			{
				$sql[] = $DB->insert_string('exp_extensions',
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
		}

		// Add count_parents column
		if ($current < '1.0.6')
		{
			$sql[] = "ALTER TABLE `exp_dc_required_cat` ADD `count_parents` INT NOT NULL DEFAULT '1'";
		}

		$sql[] = "UPDATE exp_extensions SET version = '" . $DB->escape_str($this->version) . "' WHERE class = '" . get_class($this) . "'";

		// run all sql queries
		foreach ($sql as $query)
		{
			$DB->query($query);
		}
	}

	// --------------------------------
	//	Disable Extension
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
			if ($this->debug)
			{
				echo('<pre>');
				print_r($_POST);
				echo('</pre>');
			}

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
		if($IN->GBL('M') != 'blog_admin' || ($IN->GBL('P') != 'blog_prefs' && $IN->GBL('P') !=	'update_preferences'))
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
				$DSP->input_radio('dc_required_category', '1', $this->require_cat ? 1 : 0).$LANG->line('yes').NBS.
				$DSP->input_radio('dc_required_category', '0', !$this->require_cat ? 1 : 0).$LANG->line('no'), '50%');
		$r .= $DSP->tr_c();

		//	category limit settings: Options
		$options =	$DSP->input_select_header('dc_category_limit', '', 1);

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
		$r .= $DSP->table_qcell('tableCellTwo', $options, '50%');
		$r .= $DSP->tr_c();

		//	category number has to be exact?
		$r .= $DSP->tr();
		$r .= $DSP->table_qcell('tableCellOne', $DSP->qspan('defaultBold', $LANG->line('pref_category_exact')), '50%');
		$r .= $DSP->table_qcell('tableCellOne',
				$DSP->input_radio('dc_exact_category', '1', $this->exact_cat ? 1 : 0).$LANG->line('yes').NBS.
				$DSP->input_radio('dc_exact_category', '0', !$this->exact_cat ? 1 : 0).$LANG->line('no'), '50%');
		$r .= $DSP->tr_c();

		// count parent categories?
		$r .= $DSP->tr();
		$r .= $DSP->table_qcell('tableCellTwo', $DSP->qspan('defaultBold', $LANG->line('pref_count_parents')) . $DSP->div() . $LANG->line('pref_count_parents_desc') . $DSP->div_c(), '50%');
		$r .= $DSP->table_qcell('tableCellTwo',
		  $DSP->input_radio('dc_count_parents', '1', $this->count_parents ? 1 : 0).$LANG->line('yes').NBS.
		  $DSP->input_radio('dc_count_parents', '0', !$this->count_parents ? 1 : 0).$LANG->line('no'), '50%');
		$r .= $DSP->tr_c();

		$r.= $DSP->table_c();

		//	=============================================
		//	Add Fields
		//	=============================================
		$out = @str_replace($table[0], $table[0].$r, $out);

		return $out;
	}

	/**
	 * Settings Form
	 *
	 * Construct the custom settings form.
	 *
	 * Look and feel based on LG Addon Updater's settings form.
	 *
	 * @param  array   $current	  Current extension settings (not site-specific)
	 * @see	   http://expressionengine.com/docs/development/extensions.html#settings
	 * @since  version 1.0.0
	 */
	function settings_form($current)
	{
		$current = $this->_get_site_settings($current);

		global $DB, $DSP, $LANG, $IN;

		// Breadcrumbs
		$DSP->crumbline = TRUE;

		$DSP->title = $LANG->line('extension_settings');
		$DSP->crumb = $DSP->anchor(BASE.AMP.'C=admin'.AMP.'area=utilities', $LANG->line('utilities'))
						. $DSP->crumb_item($DSP->anchor(BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=extensions_manager', $LANG->line('extensions_manager')))
						. $DSP->crumb_item($this->name);

		$DSP->right_crumb($LANG->line('disable_extension'), BASE.AMP.'C=admin'.AMP.'M=utilities'.AMP.'P=toggle_extension_confirm'.AMP.'which=disable'.AMP.'name='.$IN->GBL('name'));

		// Donations button
	    $DSP->body .= '<div style="float:right;">'
	                . '<a style="display:block; margin:0 10px 0 0; width:279px; height:27px; outline: none;'
					. ' background: url(http://www.designchuchi.ch/images/shared/donate.gif) no-repeat 0 0; text-indent: -10000em;"'
	                . ' href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&amp;hosted_button_id=3885671"'
					. ' title="'. $LANG->line('donate_title') .'" target="_blank">'
	                . $LANG->line('donate')
	                . '</a>'
	                . '</div>';

		// Form header
		$DSP->body .= "<h1>{$this->name} <small>{$this->version}</small></h1>";
		$DSP->body .= $DSP->form_open(
							array(
								'action'	=> 'C=admin'.AMP.'M=utilities'.AMP.'P=save_extension_settings',
								'name'		=> 'settings_example',
								'id'		=> 'settings_example'
							 ),
							array(
								/* thanks Leevi, based on WHAT A M*F*KING B*TCH this was, forever grateful! */
								'name'		=> strtolower(get_class($this))
							)
					  );

		// Updates Setting
		$lgau_query = $DB->query("SELECT class FROM exp_extensions WHERE class = 'Lg_addon_updater_ext' AND enabled = 'y' LIMIT 1");
		$lgau_enabled = $lgau_query->num_rows ? TRUE : FALSE;
		$check_for_extension_updates = ($lgau_enabled AND $current['check_for_updates'] == 'y') ? TRUE : FALSE;

		$DSP->body .= $DSP->table_open(
							array(
								'class'		=> 'tableBorder',
								'border'	=> '0',
								'style'		=> 'margin-top:18px; width:100%'
							)
					  )

						. $DSP->tr()
						. $DSP->td('tableHeading', '', '2')
						. $LANG->line("check_for_updates_title")
						. $DSP->td_c()
						. $DSP->tr_c()

						. $DSP->tr()
						. $DSP->td('', '', '2')
						. '<div class="box" style="border-width:0 0 1px 0; margin:0; padding:10px 5px"><p>'.$LANG->line('check_for_updates_info').'</p></div>'
						. $DSP->td_c()
						. $DSP->tr_c()

						. $DSP->tr()
						. $DSP->td('tableCellOne', '60%')
						. $DSP->qdiv('defaultBold', $LANG->line("check_for_updates_label"))
						. $DSP->td_c()

						. $DSP->td('tableCellOne')
						. '<select name="check_for_updates"'.($lgau_enabled ? '' : ' disabled="disabled"').'>'
						. $DSP->input_select_option('y', $LANG->line('yes'), ($current['check_for_updates'] == 'y' ? 'y' : ''))
						. $DSP->input_select_option('n', $LANG->line('no'),	 ($current['check_for_updates'] != 'y' ? 'y' : ''))
						. $DSP->input_select_footer()
						. ($lgau_enabled ? '' : NBS.NBS.NBS.$LANG->line('check_for_updates_nolgau'))
						. $DSP->td_c()
						. $DSP->tr_c()

						. $DSP->table_c();

		// Close Form
		$DSP->body .= $DSP->qdiv('itemWrapperTop', $DSP->input_submit()). $DSP->form_c();
	}

	/**
	 * Saves the required category preferences.
	 *
	 * @see		sessions_start hook
	 * @since	Version 1.0.0
	 */
	function save_weblog_settings() {
		global $DB;

		if (isset($_POST['weblog_id']) && isset($_POST['dc_required_category']) && isset($_POST['dc_category_limit']) && isset($_POST['dc_exact_category']) && isset($_POST['dc_count_parents']))
		{
			// insert new values or update existing ones
			$DB->query("INSERT INTO exp_dc_required_cat VALUES('', '"
				.$DB->escape_str($_POST['weblog_id'])."', '"
				.$DB->escape_str($_POST['dc_required_category'])."', '"
				.$DB->escape_str($_POST['dc_category_limit'])."', '"
				.$DB->escape_str($_POST['dc_exact_category'])."', '"
				.$DB->escape_str($_POST['dc_count_parents'])."') ON DUPLICATE KEY UPDATE
				`weblog_id` = values(`weblog_id`),
				`require_cat` = values(`require_cat`),
				`cat_limit` = values(`cat_limit`),
				`exact_cat` = values(`exact_cat`),
				`count_parents` = values(`count_parents`)"
			);

			// unset so we don't get any errors on "update" on the admin page
			unset($_POST['dc_required_category']);
			unset($_POST['dc_category_limit']);
			unset($_POST['dc_exact_category']);
			unset($_POST['dc_count_parents']);
		}
	}

	/**
	 * Save Settings
	 *
	 * @since	version 1.0.5
	 */
	function save_settings()
	{
		global $DB, $PREFS;

		$settings = $this->_get_settings();

		// Save new settings
		$settings[$PREFS->ini('site_id')] = $this->settings = array(
			'check_for_updates' => isset($_POST['check_for_updates']) ? $_POST['check_for_updates'] : 'n',
		);

		$DB->query("UPDATE exp_extensions SET settings = '" . addslashes(serialize($settings)) . "' WHERE class = '" . get_class($this) . "'");
	}

	//	========================================================================
	//	Private Functions
	//	========================================================================

	/**
	 * Sets internal preferences for a given weblog.
	 *
	 * @param	string $weblog_id A weblog id.
	 * @since	Version 1.0.0
	 */
	function _set_preferences($weblog_id) {
		global $DB;

		$preferences = $DB->query("SELECT * FROM exp_dc_required_cat WHERE weblog_id='" . $DB->escape_str($weblog_id) . "'");

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

		// set count parents value
		$this->count_parents = ($preferences->row['count_parents'] == 1) ? TRUE : FALSE;

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
	 * @param	string	$weblog_id	A weblog id.
	 * @since	Version 1.0.4
	 * @return	array	$errors		An array containing errors.
	 */
	function _check_errors($weblog_id) {

		global $LANG, $PREFS, $DB;

		$LANG->fetch_language_file('dc_required_category');

		//	=============================================
		//	Set weblog preferences
		//	=============================================
		//	Only one query for all settings
		$this->_set_preferences($weblog_id);

		// error array
		$errors = array();

		// category required
		if ($this->require_cat && empty($_POST['category']))
		{
			$errors[0] = $LANG->line('error_empty');
		}
		// check limits
		if ($this->_has_category_limit())
		{
			$cat_count = @sizeof($_POST['category']);

		   // the case parent categories should not be counted
			if ( ! $this->count_parents)
			{
				$site_id = $PREFS->ini('site_id');

				foreach ($_POST['category'] as $cat_id)
				{
					$sql = "SELECT cat_id, cat_name FROM exp_categories WHERE parent_id = '" . $DB->escape_str($cat_id) . "' AND site_id = '" . $DB->escape_str($site_id) . "'";
					$query = $DB->query($sql);

					if ($query->num_rows > 0)
					{
						$cat_count -= 1;
					}
				}
			}

			// we have more categories than permitted
			if ($cat_count > $this->cat_limit)
			{
				$errors[0] = ($this->cat_limit == 1) ? $LANG->line('error_cat_single') : str_replace('%{limit}', $this->cat_limit, $LANG->line('error_cat_limit'));
			}
			// check limit exact
			if ($this->exact_cat && ($cat_count != $this->cat_limit))
			{
				$errors[0] = ($this->cat_limit == 1) ? $LANG->line('error_cat_exact_single') : str_replace('%{limit}', $this->cat_limit, $LANG->line('error_cat_exact'));
			}
		}

		return $errors;
	}

	//	========================================================================
	//	Settings
	//	========================================================================

	/**
	 * Get All Settings
	 *
	 * @return array   All extension settings
	 * @since  version 1.0.5
	 */
	function _get_settings()
	{
		global $DB;

		$query = $DB->query("SELECT settings FROM exp_extensions WHERE class = '" . get_class($this) . "' AND settings != '' LIMIT 1");

		return $query->num_rows ? unserialize($query->row['settings']) : array();
	}

	/**
	 * Get Default Settings
	 *
	 * @return	array	Default settings for site
	 * @since	1.0.5
	 */
	function _get_default_settings()
	{
		$settings = array(
			'check_for_updates' => 'y'
		);

		return $settings;
	}

	/**
	 * Get Site Settings
	 *
	 * @param	array	$settings	Current extension settings (not site-specific)
	 * @return	array				Site-specific extension settings
	 * @since	version 1.0.5
	 */
	function _get_site_settings($settings = array())
	{
		global $PREFS;

		$site_settings = $this->_get_default_settings();
		$site_id = $PREFS->ini('site_id');

		if (isset($settings[$site_id]))
		{
			$site_settings = array_merge($site_settings, $settings[$site_id]);
		}

		return $site_settings;
	}

	//	========================================================================
	//	LG Adddon Updater Hooks
	//	========================================================================

	/**
	* Register a new Addon Source
	*
	* @param	array	$sources	The existing sources
	* @return	array				Updated addons list.
	* @since	version 1.0.5
	*/
	function dc_required_category_register_source($sources)
	{
		global $EXT;

		// -- Check if we're not the only one using this hook
		if($EXT->last_call !== FALSE)
			$sources = $EXT->last_call;

		// add a new source
		if($this->settings['check_for_updates'] == 'y')
		{
			$sources[] = 'http://www.designchuchi.ch/versions.xml';
		}

		return $sources;
	}

	/**
	* Register a new Addon
	*
	* @param	array	$addons		The existing sources
	* @return	array				Updated addons list.
	* @since	version 1.0.5
	*/
	function dc_required_category_register_addon($addons)
	{
		global $EXT;

		// -- Check if we're not the only one using this hook
		if ($EXT->last_call !== FALSE)
			$addons = $EXT->last_call;

		// add a new addon
		// the key must match the id attribute in the source xml
		// the value must be the addons current version
		if($this->settings['check_for_updates'] == 'y')
		{
			$addons[DC_REQ_CAT_ID] = $this->version;
		}

		return $addons;
	}
}
//END CLASS