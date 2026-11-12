<?php
/* Copyright (C) 2004-2018	Laurent Destailleur			<eldy@users.sourceforge.net>
 * Copyright (C) 2018-2019	Nicolas ZABOURI				<info@inovea-conseil.com>
 * Copyright (C) 2019-2024	Frédéric France				<frederic.france@free.fr>
 * Copyright (C) 2026		Kye McIntyre
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * 	\defgroup   designtrace     Module DesignTrace
 *  \brief      DesignTrace module descriptor.
 *
 *  \file       htdocs/designtrace/core/modules/modDesignTrace.class.php
 *  \ingroup    designtrace
 *  \brief      Description and activation file for module DesignTrace
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';


/**
 *  Description and activation class for module DesignTrace
 */
class modDesignTrace extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf, $langs;

		$this->db = $db;

		// Id for module (must be unique).
		// Use here a free id (See in Home -> System information -> Dolibarr for list of used modules id).
		$this->numero = 500200; // TODO Go on page https://wiki.dolibarr.org/index.php/List_of_modules_id to reserve an id number for your module

		// Key text used to identify module (for permissions, menus, etc...)
		$this->rights_class = 'designtrace';

		// Family can be 'base' (core modules),'crm','financial','hr','projects','products','ecm','technic' (transverse modules),'interface' (link with external tools),'other','...'
		// It is used to group modules by family in module setup page
		$this->family = "products";

		// Module position in the family on 2 digits ('01', '10', '20', ...)
		$this->module_position = '90';

		// Gives the possibility for the module, to provide his own family info and position of this family (Overwrite $this->family and $this->module_position. Avoid this)
		//$this->familyinfo = array('myownfamily' => array('position' => '01', 'label' => $langs->trans("MyOwnFamily")));
		// Module label (no space allowed), used if translation string 'ModuleDesignTraceName' not found (DesignTrace is name of module).
		$this->name = preg_replace('/^mod/i', '', get_class($this));

		// DESCRIPTION_FLAG
		// Module description, used if translation string 'ModuleDesignTraceDesc' not found (DesignTrace is name of module).
		$this->description = "ModuleDesignTraceDesc";
		// Used only if file README.md and README-LL.md not found.
		$this->descriptionlong = "ModuleDesignTraceDesc";

		// Enables the module for all entities (Multicompany)
		// Can be enabled / disabled only from the main company with superadmin account
		// $this->core_enabled = 1;

		// Author
		$this->editor_name = 'Kye McIntyre';
		$this->editor_url = 'https://kyemcintyre.net';		// Must be an external online web site
		$this->editor_squarred_logo = '';					// Must be image filename into the module/img directory followed with @modulename. Example: 'myimage.png@designtrace'

		// Possible values for version are: 'development', 'experimental', 'dolibarr', 'dolibarr_deprecated', 'experimental_deprecated' or a version string like 'x.y.z'
		$this->version = '0.1.0';
		// Url to the file with your last numberversion of this module
		//$this->url_last_version = 'http://www.example.com/versionmodule.txt';

		// Key used in llx_const table to save module status enabled/disabled (where DESIGNTRACE is value of property name of module in uppercase)
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);

		// Name of image file used for this module.
		// If file is in theme/yourtheme/img directory under name object_pictovalue.png, use this->picto='pictovalue'
		// If file is in module/img directory under name object_pictovalue.png, use this->picto='pictovalue@module'
		// To use a supported fa-xxx css style of font awesome, use this->picto='xxx'
		$this->picto = 'fa-drafting-compass';

		// Define some features supported by module (triggers, login, substitutions, menus, css, etc...)
		// If your module use the parameter "core_enabled" and you would like to activate the parts in all entities (Multicompany):
		// 'xxxxxx' => array(
		//		'data' => 1 or '/designtrace/xxx/designtrace.xxx.php',
		//		'entity' => '0'
		//	),
		// 'hooks' => array(
		//		'data' => array(
		//			'hookcontext1',
		//			'hookcontext2',
		//		)
		//		'entity' => '0'
		//	)
		$this->module_parts = array(
			// Set this to 1 if module has its own trigger directory (core/triggers)
			'triggers' => 1,	// Carry design revision links from quotes to orders and invoices
			// Set this to 1 if module has its own login method file (core/login)
			'login' => 0,
			// Set this to 1 if module has its own substitution function file (core/substitutions)
			'substitutions' => 0,
			// Set this to 1 if module has its own menus handler directory (core/menus)
			'menus' => 0,
			// Set this to 1 if module overwrite template dir (core/tpl)
			'tpl' => 0,
			// Set this to 1 if module has its own barcode directory (core/modules/barcode)
			'barcode' => 0,
			// Set this to 1 if module has its own models directory (core/modules/xxx)
			'models' => 1,
			// Set this to 1 if module has its own printing directory (core/modules/printing)
			'printing' => 0,
			// Set this to 1 if module has its own theme directory (theme)
			'theme' => 0,
			// Set this to relative path of css file if module has its own css file
			'css' => array(
				//    '/designtrace/css/designtrace.css.php',
			),
			// Set this to relative path of js file if module must load a js on all pages
			'js' => array(
				//   '/designtrace/js/designtrace.js.php',
			),
			// Set here all hooks context managed by module. To find available hook context, make a "grep -r '>initHooks(' *" on source code. You can also set hook context to 'all'
			/* BEGIN MODULEBUILDER HOOKSCONTEXTS */
			'hooks' => array(
				'propalcard',	// "Create design" button
				'ordercard',
				'bomcard',		// Design revision of the BOM, warning when it needs updating
				'ticketcard',	// "Raise ECO" button
				'webportal',	// Web portal: Design files menu entry and column on quote/order lists
				'webportaldao',	// Web portal: registers the Design files page (initController)
			),
			/* END MODULEBUILDER HOOKSCONTEXTS */
			// Set this to 1 if features of module are opened to external users
			'moduleforexternal' => 0,
			// Set this to 1 if the module provides a website template into doctemplates/websites/website_template-mytemplate
			'websitetemplates' => 0,
			// Set this to 1 if the module provides a captcha driver
			'captcha' => 0
		);

		// Data directories to create when module is enabled.
		// Example: this->dirs = array("/designtrace/temp","/designtrace/subdir");
		$this->dirs = array("/designtrace/temp");

		// Config pages. Put here list of php page, stored into designtrace/admin directory, to use to setup module.
		$this->config_page_url = array("setup.php@designtrace");

		// Dependencies
		// A condition to hide module
		$this->hidden = getDolGlobalInt('MODULE_DESIGNTRACE_DISABLED'); // A condition to disable module;
		// List of module class names that must be enabled if this module is enabled. Example: array('always'=>array('modModuleToEnable1','modModuleToEnable2'), 'FR'=>array('modModuleToEnableFR')...)
		$this->depends = array();
		// List of module class names to disable if this one is disabled. Example: array('modModuleToDisable1', ...)
		$this->requiredby = array();
		// List of module class names this module is in conflict with. Example: array('modModuleToDisable1', ...)
		$this->conflictwith = array();

		// The language file dedicated to your module
		$this->langfiles = array("designtrace@designtrace");

		// Prerequisites
		$this->phpmin = array(8, 1); // Minimum version of PHP required by module
		// $this->phpmax = array(8, 0); // Maximum version of PHP required by module
		$this->need_dolibarr_version = array(24, 0); // Minimum version of Dolibarr required by module
		// $this->max_dolibarr_version = array(19, -3); // Maximum version of Dolibarr required by module
		$this->need_javascript_ajax = 0;

		// Messages at activation
		$this->warnings_activation = array(); 		// Warning to show when we activate a module. Example: array('always'='text') or array('FR'='textfr','MX'='textmx'...)
		$this->warnings_activation_ext = array(); 	// Warning to show when we activate a module if another module is on. Example: array('modOtherModule' => array('always'=>'text')) or array('always' => array('FR'=>'textfr','MX'=>'textmx'...))
		//$this->automatic_activation = array('FR'=>'DesignTraceWasAutomaticallyActivatedBecauseOfYourCountryChoice');
		//$this->always_enabled = false;			// If true, can't be disabled. Value true is reserved for core modules. Not allowed for external modules.

		// Constants
		// List of particular constants to add when module is enabled (key, 'chaine', value, desc, visible, 'current' or 'allentities', deleteonunactive)
		// Example: $this->const=array(1 => array('DESIGNTRACE_MYNEWCONST1', 'chaine', 'myvalue', 'This is a constant to add', 1),
		//                             2 => array('DESIGNTRACE_MYNEWCONST2', 'chaine', 'myvalue', 'This is another constant to add', 0, 'current', 1)
		// );
		$this->const = array();

		// Some keys to add into the overwriting translation tables
		/*$this->overwrite_translation = array(
			'en_US:ParentCompany'=>'Parent company or reseller',
			'fr_FR:ParentCompany'=>'Maison mère ou revendeur'
		)*/

		if (!isModEnabled("designtrace")) {
			$conf->designtrace = new stdClass();
			$conf->designtrace->enabled = 0;
		}

		// Array to add new pages in new tabs
		/* BEGIN MODULEBUILDER TABS */
		// Don't forget to deactivate/reactivate your module to test your changes
		$this->tabs = array();
		/* END MODULEBUILDER TABS */
		// Example:
		// To add a new tab identified by code tabname1
		// $this->tabs[] = array('data' => 'objecttype:+tabname1:Title1:mylangfile@designtrace:$user->hasRight('designtrace', 'design', 'read'):/designtrace/mynewtab1.php?id=__ID__');
		// To add another new tab identified by code tabname2. Label will be result of calling all substitution functions on 'Title2' key.
		// $this->tabs[] = array('data' => 'objecttype:+tabname2:SUBSTITUTION_Title2:mylangfile@designtrace:$user->hasRight('othermodule', 'otherobject', 'read'):/designtrace/mynewtab2.php?id=__ID__',
		// To remove an existing tab identified by code tabname
		// $this->tabs[] = array('data' => 'objecttype:-tabname:NU:conditiontoremove');
		//
		// Where objecttype can be
		// 'categories_x'	  to add a tab in category view (replace 'x' by type of category (0=product, 1=supplier, 2=customer, 3=member)
		// 'contact'          to add a tab in contact view
		// 'contract'         to add a tab in contract view
		// 'delivery'         to add a tab in delivery view
		// 'group'            to add a tab in group view
		// 'intervention'     to add a tab in intervention view
		// 'invoice'          to add a tab in customer invoice view
		// 'supplier_invoice' to add a tab in supplier invoice view
		// 'member'           to add a tab in foundation member view
		// 'opensurveypoll'	  to add a tab in opensurvey poll view
		// 'order'            to add a tab in sale order view
		// 'supplier_order'   to add a tab in supplier order view
		// 'payment'		  to add a tab in payment view
		// 'supplier_payment' to add a tab in supplier payment view
		// 'product'          to add a tab in product view
		// 'propal'           to add a tab in propal view
		// 'project'          to add a tab in project view
		// 'stock'            to add a tab in stock view
		// 'thirdparty'       to add a tab in third party view
		// 'user'             to add a tab in user view


		// Dictionaries
		/* Example:
		 $this->dictionaries=array(
		 'langs' => 'designtrace@designtrace',
		 // List of tables we want to see into dictionary editor
		 'tabname' => array("table1", "table2", "table3"),
		 // Label of tables
		 'tablib' => array("Table1", "Table2", "Table3"),
		 // Request to select fields
		 'tabsql' => array('SELECT f.rowid as rowid, f.code, f.label, f.active FROM '.$this->db->prefix().'table1 as f', 'SELECT f.rowid as rowid, f.code, f.label, f.active FROM '.$this->db->prefix().'table2 as f', 'SELECT f.rowid as rowid, f.code, f.label, f.active FROM '.$this->db->prefix().'table3 as f'),
		 // Sort order
		 'tabsqlsort' => array("label ASC", "label ASC", "label ASC"),
		 // List of fields (result of select to show dictionary)
		 'tabfield' => array("code,label", "code,label", "code,label"),
		 // List of fields (list of fields to edit a record)
		 'tabfieldvalue' => array("code,label", "code,label", "code,label"),
		 // List of fields (list of fields for insert)
		 'tabfieldinsert' => array("code,label", "code,label", "code,label"),
		 // Name of columns with primary key (try to always name it 'rowid')
		 'tabrowid' => array("rowid", "rowid", "rowid"),
		 // Condition to show each dictionary
		 'tabcond' => array(isModEnabled('designtrace'), isModEnabled('designtrace'), isModEnabled('designtrace')),
		 // Tooltip for every fields of dictionaries: DO NOT PUT AN EMPTY ARRAY
		 'tabhelp' => array(array('code' => $langs->trans('CodeTooltipHelp'), 'field2' => 'field2tooltip'), array('code' => $langs->trans('CodeTooltipHelp'), 'field2' => 'field2tooltip'), ...),
		 );
		 */
		/* BEGIN MODULEBUILDER DICTIONARIES */
		$this->dictionaries = array();
		/* END MODULEBUILDER DICTIONARIES */

		// Boxes/Widgets
		// Add here list of php file(s) stored in designtrace/core/boxes that contains a class to show a widget.
		/* BEGIN MODULEBUILDER WIDGETS */
		$this->boxes = array(
			//  0 => array(
			//      'file' => 'designtracewidget1.php@designtrace',
			//      'note' => 'Widget provided by DesignTrace',
			//      'enabledbydefaulton' => 'Home',
			//  ),
			//  ...
		);
		/* END MODULEBUILDER WIDGETS */

		// Cronjobs (List of cron jobs entries to add when module is enabled)
		// unit_frequency must be 60 for minute, 3600 for hour, 86400 for day, 604800 for week
		/* BEGIN MODULEBUILDER CRON */
		$this->cronjobs = array(
			//  0 => array(
			//      'label' => 'MyJob label',
			//      'jobtype' => 'method',
			//      'class' => '/designtrace/class/design.class.php',
			//      'objectname' => 'Design',
			//      'method' => 'doScheduledJob',
			//      'parameters' => '',
			//      'comment' => 'Comment',
			//      'frequency' => 2,
			//      'unitfrequency' => 3600,
			//      'status' => 0,
			//      'test' => 'isModEnabled("designtrace")',
			//      'priority' => 50,
			//  ),
		);
		/* END MODULEBUILDER CRON */
		// Example: $this->cronjobs=array(
		//    0=>array('label'=>'My label', 'jobtype'=>'method', 'class'=>'/dir/class/file.class.php', 'objectname'=>'MyClass', 'method'=>'myMethod', 'parameters'=>'param1, param2', 'comment'=>'Comment', 'frequency'=>2, 'unitfrequency'=>3600, 'status'=>0, 'test'=>'isModEnabled("designtrace")', 'priority'=>50),
		//    1=>array('label'=>'My label', 'jobtype'=>'command', 'command'=>'', 'parameters'=>'param1, param2', 'comment'=>'Comment', 'frequency'=>1, 'unitfrequency'=>3600*24, 'status'=>0, 'test'=>'isModEnabled("designtrace")', 'priority'=>50)
		// );

		// Permissions provided by this module
		$this->rights = array();
		$r = 0;
		// Add here entries to declare new permissions
		/* BEGIN MODULEBUILDER PERMISSIONS */
		$this->rights[$r][0] = $this->numero . sprintf('%02d', (0 * 10) + 0 + 1);
		$this->rights[$r][1] = 'Read Design object of DesignTrace';
		$this->rights[$r][4] = 'design';
		$this->rights[$r][5] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero . sprintf('%02d', (0 * 10) + 1 + 1);
		$this->rights[$r][1] = 'Create/Update Design object of DesignTrace';
		$this->rights[$r][4] = 'design';
		$this->rights[$r][5] = 'write';
		$r++;
		$this->rights[$r][0] = $this->numero . sprintf('%02d', (0 * 10) + 2 + 1);
		$this->rights[$r][1] = 'Delete Design object of DesignTrace';
		$this->rights[$r][4] = 'design';
		$this->rights[$r][5] = 'delete';
		$r++;
		$this->rights[$r][0] = $this->numero . sprintf('%02d', (1 * 10) + 0 + 1);
		$this->rights[$r][1] = 'Read Component object of DesignTrace';
		$this->rights[$r][4] = 'component';
		$this->rights[$r][5] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero . sprintf('%02d', (1 * 10) + 1 + 1);
		$this->rights[$r][1] = 'Create/Update Component object of DesignTrace';
		$this->rights[$r][4] = 'component';
		$this->rights[$r][5] = 'write';
		$r++;
		$this->rights[$r][0] = $this->numero . sprintf('%02d', (1 * 10) + 2 + 1);
		$this->rights[$r][1] = 'Delete Component object of DesignTrace';
		$this->rights[$r][4] = 'component';
		$this->rights[$r][5] = 'delete';
		$r++;
		$this->rights[$r][0] = $this->numero . sprintf('%02d', (2 * 10) + 0 + 1);
		$this->rights[$r][1] = 'Read ComponentRevision object of DesignTrace';
		$this->rights[$r][4] = 'componentrevision';
		$this->rights[$r][5] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero . sprintf('%02d', (2 * 10) + 1 + 1);
		$this->rights[$r][1] = 'Create/Update ComponentRevision object of DesignTrace';
		$this->rights[$r][4] = 'componentrevision';
		$this->rights[$r][5] = 'write';
		$r++;
		$this->rights[$r][0] = $this->numero . sprintf('%02d', (2 * 10) + 2 + 1);
		$this->rights[$r][1] = 'Delete ComponentRevision object of DesignTrace';
		$this->rights[$r][4] = 'componentrevision';
		$this->rights[$r][5] = 'delete';
		$r++;
		$this->rights[$r][0] = $this->numero . sprintf('%02d', (4 * 10) + 0 + 1);
		$this->rights[$r][1] = 'Read change orders (ECO) of DesignTrace';
		$this->rights[$r][4] = 'changeorder';
		$this->rights[$r][5] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero . sprintf('%02d', (4 * 10) + 1 + 1);
		$this->rights[$r][1] = 'Create/Update change orders (ECO) of DesignTrace';
		$this->rights[$r][4] = 'changeorder';
		$this->rights[$r][5] = 'write';
		$r++;
		$this->rights[$r][0] = $this->numero . sprintf('%02d', (4 * 10) + 2 + 1);
		$this->rights[$r][1] = 'Delete change orders (ECO) of DesignTrace';
		$this->rights[$r][4] = 'changeorder';
		$this->rights[$r][5] = 'delete';
		$r++;
		$this->rights[$r][0] = $this->numero . sprintf('%02d', (5 * 10) + 0 + 1);
		$this->rights[$r][1] = 'Read licence templates of DesignTrace';
		$this->rights[$r][4] = 'licence';
		$this->rights[$r][5] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero . sprintf('%02d', (5 * 10) + 1 + 1);
		$this->rights[$r][1] = 'Manage licence templates of DesignTrace (create, new versions, activate)';
		$this->rights[$r][4] = 'licence';
		$this->rights[$r][5] = 'write';
		$r++;
		$this->rights[$r][0] = $this->numero . sprintf('%02d', (5 * 10) + 2 + 1);
		$this->rights[$r][1] = 'Delete licence templates of DesignTrace (never used ones only)';
		$this->rights[$r][4] = 'licence';
		$this->rights[$r][5] = 'delete';
		$r++;

		/* END MODULEBUILDER PERMISSIONS */

		// Release revisions: kept outside the Module Builder block so regenerating it keeps this right
		$this->rights[$r][0] = $this->numero . sprintf('%02d', (2 * 10) + 3 + 1);
		$this->rights[$r][1] = 'Release ComponentRevision object of DesignTrace';
		$this->rights[$r][4] = 'componentrevision';
		$this->rights[$r][5] = 'release';
		$r++;

		// Delete files of released revisions (logged): also outside the Module Builder block
		$this->rights[$r][0] = $this->numero . sprintf('%02d', (2 * 10) + 3 + 2);
		$this->rights[$r][1] = 'Delete files of released ComponentRevision objects of DesignTrace';
		$this->rights[$r][4] = 'componentrevision';
		$this->rights[$r][5] = 'deletereleasedfile';
		$r++;

		// Acknowledge a "Needs update" flag (no change needed)
		$this->rights[$r][0] = $this->numero . sprintf('%02d', (2 * 10) + 3 + 3);
		$this->rights[$r][1] = 'Acknowledge Needs update flags of DesignTrace (no change needed)';
		$this->rights[$r][4] = 'componentrevision';
		$this->rights[$r][5] = 'acknowledgeflag';
		$r++;

		// Choose which files and links customers can see (phase 5)
		$this->rights[$r][0] = $this->numero . sprintf('%02d', (2 * 10) + 3 + 4);
		$this->rights[$r][1] = 'Manage client visibility of files and document links of DesignTrace';
		$this->rights[$r][4] = 'componentrevision';
		$this->rights[$r][5] = 'managevisibility';
		$r++;

		// Update delivered revision on a delivered order (logged)
		$this->rights[$r][0] = $this->numero . sprintf('%02d', (2 * 10) + 3 + 5);
		$this->rights[$r][1] = 'Update the delivered revision of a delivered order (DesignTrace)';
		$this->rights[$r][4] = 'componentrevision';
		$this->rights[$r][5] = 'updatedelivered';
		$r++;

		// For the service user of a customer portal (My workshop) calling the REST API
		$this->rights[$r][0] = $this->numero . sprintf('%02d', (2 * 10) + 3 + 6);
		$this->rights[$r][1] = 'Customer files through the API (list visible files, signed download links)';
		$this->rights[$r][4] = 'clientfiles';
		$this->rights[$r][5] = 'api';
		$r++;

		// Approve change orders (ECO): separate from write so the requester cannot approve alone
		$this->rights[$r][0] = $this->numero . sprintf('%02d', (4 * 10) + 3 + 1);
		$this->rights[$r][1] = 'Approve change orders (ECO) of DesignTrace';
		$this->rights[$r][4] = 'changeorder';
		$this->rights[$r][5] = 'approve';
		$r++;


		// Main menu entries to add
		$this->menu = array();
		$r = 0;
		// Add here entries to declare new menus
		/* BEGIN MODULEBUILDER TOPMENU */
		$this->menu[$r++] = array(
			'fk_menu' => '', // Will be stored into mainmenu + leftmenu. Use '' if this is a top menu. For left menu, use 'fk_mainmenu=xxx' or 'fk_mainmenu=xxx,fk_leftmenu=yyy' where xxx is mainmenucode and yyy is a leftmenucode
			'type' => 'top', // This is a Top menu entry
			'titre' => 'ModuleDesignTraceName',
			'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle"'),
			'mainmenu' => 'designtrace',
			'leftmenu' => '',
			'url' => '/designtrace/designtraceindex.php',
			'langs' => 'designtrace@designtrace', // Lang file to use (without .lang) by module. File must be in langs/code_CODE/ directory.
			'position' => 1000 + $r,
			'enabled' => "isModEnabled('designtrace')", // Define condition to show or hide menu entry. Use "isModEnabled('designtrace')" if entry must be visible if module is enabled (those quote marks are importants).
			'perms' => '1', // Use 'perms'=>'$user->hasRight("designtrace", "design", "read")' if you want your menu with a permission rules
			'target' => '',
			'user' => 2, // 0=Menu for internal users, 1=external users, 2=both
		);
		/* END MODULEBUILDER TOPMENU */

		/* BEGIN MODULEBUILDER LEFTMENU DESIGN */
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=designtrace',
			'type' => 'left',
			'titre' => 'Designs',
			'prefix' => img_picto('', $this->picto, 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'designtrace',
			'leftmenu' => 'design',
			'url' => '/designtrace/design_list.php',
			'langs' => 'designtrace@designtrace',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("designtrace")',
			'perms' => '$user->hasRight("designtrace", "design", "read")',
			'target' => '',
			'user' => 2,
			'object' => 'Design'
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=designtrace,fk_leftmenu=design',
			'type' => 'left',
			'titre' => 'ListOfDesigns',
			'mainmenu' => 'designtrace',
			'leftmenu' => 'designtrace_design_list',
			'url' => '/designtrace/design_list.php',
			'langs' => 'designtrace@designtrace',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("designtrace")',
			'perms' => '$user->hasRight("designtrace", "design", "read")',
			'target' => '',
			'user' => 2,
			'object' => 'Design'
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=designtrace,fk_leftmenu=design',
			'type' => 'left',
			'titre' => 'NewDesign',
			'mainmenu' => 'designtrace',
			'leftmenu' => 'designtrace_design_new',
			'url' => '/designtrace/design_card.php?action=create',
			'langs' => 'designtrace@designtrace',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("designtrace")',
			'perms' => '$user->hasRight("designtrace", "design", "write")',
			'target' => '',
			'user' => 2,
			'object' => 'Design'
		);
		/* END MODULEBUILDER LEFTMENU DESIGN */
		/* BEGIN MODULEBUILDER LEFTMENU COMPONENT */
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=designtrace',
			'type' => 'left',
			'titre' => 'Components',
			'prefix' => img_picto('', $this->picto, 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'designtrace',
			'leftmenu' => 'component',
			'url' => '/designtrace/component_list.php',
			'langs' => 'designtrace@designtrace',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("designtrace")',
			'perms' => '$user->hasRight("designtrace", "component", "read")',
			'target' => '',
			'user' => 2,
			'object' => 'Component'
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=designtrace,fk_leftmenu=component',
			'type' => 'left',
			'titre' => 'ListOfComponents',
			'mainmenu' => 'designtrace',
			'leftmenu' => 'designtrace_component_list',
			'url' => '/designtrace/component_list.php',
			'langs' => 'designtrace@designtrace',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("designtrace")',
			'perms' => '$user->hasRight("designtrace", "component", "read")',
			'target' => '',
			'user' => 2,
			'object' => 'Component'
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=designtrace,fk_leftmenu=component',
			'type' => 'left',
			'titre' => 'NewComponent',
			'mainmenu' => 'designtrace',
			'leftmenu' => 'designtrace_component_new',
			'url' => '/designtrace/component_card.php?action=create',
			'langs' => 'designtrace@designtrace',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("designtrace")',
			'perms' => '$user->hasRight("designtrace", "component", "write")',
			'target' => '',
			'user' => 2,
			'object' => 'Component'
		);
		/* END MODULEBUILDER LEFTMENU COMPONENT */
		/* BEGIN MODULEBUILDER LEFTMENU COMPONENTREVISION */
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=designtrace',
			'type' => 'left',
			'titre' => 'ComponentRevisions',
			'prefix' => img_picto('', $this->picto, 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'designtrace',
			'leftmenu' => 'componentrevision',
			'url' => '/designtrace/componentrevision_list.php',
			'langs' => 'designtrace@designtrace',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("designtrace")',
			'perms' => '$user->hasRight("designtrace", "componentrevision", "read")',
			'target' => '',
			'user' => 2,
			'object' => 'ComponentRevision'
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=designtrace,fk_leftmenu=componentrevision',
			'type' => 'left',
			'titre' => 'ListOfComponentRevisions',
			'mainmenu' => 'designtrace',
			'leftmenu' => 'designtrace_componentrevision_list',
			'url' => '/designtrace/componentrevision_list.php',
			'langs' => 'designtrace@designtrace',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("designtrace")',
			'perms' => '$user->hasRight("designtrace", "componentrevision", "read")',
			'target' => '',
			'user' => 2,
			'object' => 'ComponentRevision'
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=designtrace,fk_leftmenu=componentrevision',
			'type' => 'left',
			'titre' => 'NewComponentRevision',
			'mainmenu' => 'designtrace',
			'leftmenu' => 'designtrace_componentrevision_new',
			'url' => '/designtrace/componentrevision_card.php?action=create',
			'langs' => 'designtrace@designtrace',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("designtrace")',
			'perms' => '$user->hasRight("designtrace", "componentrevision", "write")',
			'target' => '',
			'user' => 2,
			'object' => 'ComponentRevision'
		);
		/* END MODULEBUILDER LEFTMENU COMPONENTREVISION */
		/* BEGIN MODULEBUILDER LEFTMENU CHANGEORDER */
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=designtrace',
			'type' => 'left',
			'titre' => 'ChangeOrders',
			'prefix' => img_picto('', $this->picto, 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'designtrace',
			'leftmenu' => 'changeorder',
			'url' => '/designtrace/changeorder_list.php',
			'langs' => 'designtrace@designtrace',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("designtrace")',
			'perms' => '$user->hasRight("designtrace", "changeorder", "read")',
			'target' => '',
			'user' => 2,
			'object' => 'ChangeOrder'
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=designtrace,fk_leftmenu=changeorder',
			'type' => 'left',
			'titre' => 'ListOfChangeOrders',
			'mainmenu' => 'designtrace',
			'leftmenu' => 'designtrace_changeorder_list',
			'url' => '/designtrace/changeorder_list.php',
			'langs' => 'designtrace@designtrace',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("designtrace")',
			'perms' => '$user->hasRight("designtrace", "changeorder", "read")',
			'target' => '',
			'user' => 2,
			'object' => 'ChangeOrder'
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=designtrace,fk_leftmenu=changeorder',
			'type' => 'left',
			'titre' => 'NewChangeOrder',
			'mainmenu' => 'designtrace',
			'leftmenu' => 'designtrace_changeorder_new',
			'url' => '/designtrace/changeorder_card.php?action=create',
			'langs' => 'designtrace@designtrace',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("designtrace")',
			'perms' => '$user->hasRight("designtrace", "changeorder", "write")',
			'target' => '',
			'user' => 2,
			'object' => 'ChangeOrder'
		);
		/* END MODULEBUILDER LEFTMENU CHANGEORDER */
		/* BEGIN MODULEBUILDER LEFTMENU LICENCE */
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=designtrace',
			'type' => 'left',
			'titre' => 'LicenceTemplates',
			'prefix' => img_picto('', $this->picto, 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'designtrace',
			'leftmenu' => 'licence',
			'url' => '/designtrace/licence_list.php',
			'langs' => 'designtrace@designtrace',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("designtrace")',
			'perms' => '$user->hasRight("designtrace", "licence", "read")',
			'target' => '',
			'user' => 2,
			'object' => 'Licence'
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=designtrace,fk_leftmenu=licence',
			'type' => 'left',
			'titre' => 'ListOfLicences',
			'mainmenu' => 'designtrace',
			'leftmenu' => 'designtrace_licence_list',
			'url' => '/designtrace/licence_list.php',
			'langs' => 'designtrace@designtrace',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("designtrace")',
			'perms' => '$user->hasRight("designtrace", "licence", "read")',
			'target' => '',
			'user' => 2,
			'object' => 'Licence'
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=designtrace,fk_leftmenu=licence',
			'type' => 'left',
			'titre' => 'NewLicence',
			'mainmenu' => 'designtrace',
			'leftmenu' => 'designtrace_licence_new',
			'url' => '/designtrace/licence_card.php?action=create',
			'langs' => 'designtrace@designtrace',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("designtrace")',
			'perms' => '$user->hasRight("designtrace", "licence", "write")',
			'target' => '',
			'user' => 2,
			'object' => 'Licence'
		);
		/* END MODULEBUILDER LEFTMENU LICENCE */
		/* BEGIN MODULEBUILDER LEFTMENU MYOBJECT */
		/*
		$this->menu[$r++]=array(
			'fk_menu' => 'fk_mainmenu=designtrace',      // '' if this is a top menu. For left menu, use 'fk_mainmenu=xxx' or 'fk_mainmenu=xxx,fk_leftmenu=yyy' where xxx is mainmenucode and yyy is a leftmenucode
			'type' => 'left',                          // This is a Left menu entry
			'titre' => 'Design',
			'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle paddingright"'),
			'mainmenu' => 'designtrace',
			'leftmenu' => 'design',
			'url' => '/designtrace/designtraceindex.php',
			'langs' => 'designtrace@designtrace',	        // Lang file to use (without .lang) by module. File must be in langs/code_CODE/ directory.
			'position' => 1000 + $r,
			'enabled' => "isModEnabled('designtrace')", // Define condition to show or hide menu entry. Use isModEnabled("designtrace") if entry must be visible if module is enabled.
			'perms' => '$user->hasRight("designtrace", "design", "read")',
			'target' => '',
			'user' => 2,				                // 0=Menu for internal users, 1=external users, 2=both
			'object' => 'Design'
		);
		$this->menu[$r++]=array(
			'fk_menu' => 'fk_mainmenu=designtrace,fk_leftmenu=design',	    // '' if this is a top menu. For left menu, use 'fk_mainmenu=xxx' or 'fk_mainmenu=xxx,fk_leftmenu=yyy' where xxx is mainmenucode and yyy is a leftmenucode
			'type' => 'left',			                // This is a Left menu entry
			'titre' => 'New_Design',
			'mainmenu' => 'designtrace',
			'leftmenu' => 'designtrace_design_new',
			'url' => '/designtrace/design_card.php?action=create',
			'langs' => 'designtrace@designtrace',	        // Lang file to use (without .lang) by module. File must be in langs/code_CODE/ directory.
			'position' => 1000 + $r,
			'enabled' => "isModEnabled('designtrace')", // Define condition to show or hide menu entry. Use isModEnabled("designtrace") if entry must be visible if module is enabled. Use '$leftmenu==\'system\'' to show if leftmenu system is selected.
			'perms' => '$user->hasRight("designtrace", "design", "write")'
			'target' => '',
			'user' => 2,				                // 0=Menu for internal users, 1=external users, 2=both
			'object' => 'Design'
		);
		$this->menu[$r++]=array(
			'fk_menu' => 'fk_mainmenu=designtrace,fk_leftmenu=design',	    // '' if this is a top menu. For left menu, use 'fk_mainmenu=xxx' or 'fk_mainmenu=xxx,fk_leftmenu=yyy' where xxx is mainmenucode and yyy is a leftmenucode
			'type' => 'left',			                // This is a Left menu entry
			'titre' => 'List_Design',
			'mainmenu' => 'designtrace',
			'leftmenu' => 'designtrace_design_list',
			'url' => '/designtrace/design_list.php',
			'langs' => 'designtrace@designtrace',	        // Lang file to use (without .lang) by module. File must be in langs/code_CODE/ directory.
			'position' => 1000 + $r,
			'enabled' => "isModEnabled('designtrace')", // Define condition to show or hide menu entry. Use isModEnabled("designtrace") if entry must be visible if module is enabled.
			'perms' => '$user->hasRight("designtrace", "design", "read")'
			'target' => '',
			'user' => 2,				                // 0=Menu for internal users, 1=external users, 2=both
			'object' => 'Design'
		);
		*/
		/* END MODULEBUILDER LEFTMENU MYOBJECT */

		// Global list of flagged components (outside the Module Builder blocks)
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=designtrace',
			'type' => 'left',
			'titre' => 'NeedsUpdateList',
			'prefix' => img_picto('', 'fa-exclamation-triangle', 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'designtrace',
			'leftmenu' => 'designtrace_needsupdate',
			'url' => '/designtrace/needsupdate_list.php',
			'langs' => 'designtrace@designtrace',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("designtrace")',
			'perms' => '$user->hasRight("designtrace", "componentrevision", "read")',
			'target' => '',
			'user' => 0,
		);
		// Customer downloads (Web portal and REST API) across all revisions
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=designtrace',
			'type' => 'left',
			'titre' => 'DownloadLog',
			'prefix' => img_picto('', 'fa-download', 'class="paddingright pictofixedwidth valignmiddle"'),
			'mainmenu' => 'designtrace',
			'leftmenu' => 'designtrace_downloads',
			'url' => '/designtrace/download_list.php',
			'langs' => 'designtrace@designtrace',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("designtrace")',
			'perms' => '$user->hasRight("designtrace", "componentrevision", "read")',
			'target' => '',
			'user' => 0,
		);


		// Exports profiles provided by this module
		$r = 0;
		/* BEGIN MODULEBUILDER EXPORT MYOBJECT */
		/*
		$langs->load("designtrace@designtrace");
		$this->export_code[$r] = $this->rights_class.'_'.$r;
		$this->export_label[$r] = 'DesignLines';	// Translation key (used only if key ExportDataset_xxx_z not found)
		$this->export_icon[$r] = $this->picto;
		// Define $this->export_fields_array, $this->export_TypeFields_array and $this->export_entities_array
		$keyforclass = 'Design'; $keyforclassfile='/designtrace/class/design.class.php'; $keyforelement='design@designtrace';
		include DOL_DOCUMENT_ROOT.'/core/commonfieldsinexport.inc.php';
		//$this->export_fields_array[$r]['t.fieldtoadd']='FieldToAdd'; $this->export_TypeFields_array[$r]['t.fieldtoadd']='Text';
		//unset($this->export_fields_array[$r]['t.fieldtoremove']);
		//$keyforclass = 'DesignLine'; $keyforclassfile='/designtrace/class/design.class.php'; $keyforelement='designline@designtrace'; $keyforalias='tl';
		//include DOL_DOCUMENT_ROOT.'/core/commonfieldsinexport.inc.php';
		$keyforselect='design'; $keyforaliasextra='extra'; $keyforelement='design@designtrace';
		include DOL_DOCUMENT_ROOT.'/core/extrafieldsinexport.inc.php';
		//$keyforselect='designline'; $keyforaliasextra='extraline'; $keyforelement='designline@designtrace';
		//include DOL_DOCUMENT_ROOT.'/core/extrafieldsinexport.inc.php';
		//$this->export_dependencies_array[$r] = array('designline' => array('tl.rowid','tl.ref')); // To force to activate one or several fields if we select some fields that need same (like to select a unique key if we ask a field of a child to avoid the DISTINCT to discard them, or for computed field than need several other fields)
		//$this->export_special_array[$r] = array('t.field' => '...');
		//$this->export_examplevalues_array[$r] = array('t.field' => 'Example');
		//$this->export_help_array[$r] = array('t.field' => 'FieldDescHelp');
		$this->export_sql_start[$r]='SELECT DISTINCT ';
		$this->export_sql_end[$r]  =' FROM '.$this->db->prefix().'designtrace_design as t';
		//$this->export_sql_end[$r]  .=' LEFT JOIN '.$this->db->prefix().'designtrace_design_line as tl ON tl.fk_design = t.rowid';
		$this->export_sql_end[$r] .=' WHERE 1 = 1';
		$this->export_sql_end[$r] .=' AND t.entity IN ('.getEntity('design').')';
		$r++; */
		/* END MODULEBUILDER EXPORT MYOBJECT */

		// Imports profiles provided by this module
		$r = 0;
		/* BEGIN MODULEBUILDER IMPORT MYOBJECT */
		/*
		$langs->load("designtrace@designtrace");
		$this->import_code[$r] = $this->rights_class.'_'.$r;
		$this->import_label[$r] = 'DesignLines';	// Translation key (used only if key ExportDataset_xxx_z not found)
		$this->import_icon[$r] = $this->picto;
		$this->import_tables_array[$r] = array('t' => $this->db->prefix().'designtrace_design', 'extra' => $this->db->prefix().'designtrace_design_extrafields');
		$this->import_tables_creator_array[$r] = array('t' => 'fk_user_author'); // Fields to store import user id
		$import_sample = array();
		$keyforclass = 'Design'; $keyforclassfile='/designtrace/class/design.class.php'; $keyforelement='design@designtrace';
		include DOL_DOCUMENT_ROOT.'/core/commonfieldsinimport.inc.php';
		$import_extrafield_sample = array();
		$keyforselect='design'; $keyforaliasextra='extra'; $keyforelement='design@designtrace';
		include DOL_DOCUMENT_ROOT.'/core/extrafieldsinimport.inc.php';
		$this->import_fieldshidden_array[$r] = array('extra.fk_object' => 'lastrowid-'.$this->db->prefix().'designtrace_design');
		$this->import_regex_array[$r] = array();
		$this->import_examplevalues_array[$r] = array_merge($import_sample, $import_extrafield_sample);
		$this->import_updatekeys_array[$r] = array('t.ref' => 'Ref');
		$this->import_convertvalue_array[$r] = array(
			't.ref' => array(
				'rule'=>'getrefifauto',
				'class'=>(!getDolGlobalString('DESIGNTRACE_DESIGN_ADDON') ? 'mod_design_standard' : getDolGlobalString('DESIGNTRACE_DESIGN_ADDON')),
				'path'=>"/core/modules/designtrace/".(!getDolGlobalString('DESIGNTRACE_DESIGN_ADDON') ? 'mod_design_standard' : getDolGlobalString('DESIGNTRACE_DESIGN_ADDON')).'.php',
				'classobject'=>'Design',
				'pathobject'=>'/designtrace/class/design.class.php',
			),
			't.fk_soc' => array('rule' => 'fetchidfromref', 'file' => '/societe/class/societe.class.php', 'class' => 'Societe', 'method' => 'fetch', 'element' => 'ThirdParty'),
			't.fk_user_valid' => array('rule' => 'fetchidfromref', 'file' => '/user/class/user.class.php', 'class' => 'User', 'method' => 'fetch', 'element' => 'user'),
			't.fk_mode_reglement' => array('rule' => 'fetchidfromcodeorlabel', 'file' => '/compta/paiement/class/cpaiement.class.php', 'class' => 'Cpaiement', 'method' => 'fetch', 'element' => 'cpayment'),
		);
		$this->import_run_sql_after_array[$r] = array();
		$r++; */
		/* END MODULEBUILDER IMPORT MYOBJECT */
	}

	/**
	 *  Function called when module is enabled.
	 *  The init function add constants, boxes, permissions and menus (defined in constructor) into Dolibarr database.
	 *  It also creates data directories
	 *
	 *  @param      string  $options    Options when enabling module ('', 'noboxes')
	 *  @return     int<-1,1>          	1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		global $conf, $langs;

		// Create tables of module at module activation
		//$result = $this->_load_tables('/install/mysql/', 'designtrace');
		$result = $this->_load_tables('/designtrace/sql/');
		if ($result < 0) {
			return -1; // Do not activate module if error 'not allowed' returned when loading module SQL queries (the _load_table run sql with run_sql with the error allowed parameter set to 'default')
		}

		// Create extrafields during init
		//include_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		//$extrafields = new ExtraFields($this->db);
		//$result0=$extrafields->addExtraField('designtrace_separator1', "Separator 1", 'separator', 1,  0, 'thirdparty',   0, 0, '', array('options'=>array(1=>1)), 1, '', 1, 0, '', '', 'designtrace@designtrace', 'isModEnabled("designtrace")');
		//$result1=$extrafields->addExtraField('designtrace_myattr1', "New Attr 1 label", 'boolean', 1,  3, 'thirdparty',   0, 0, '', '', 1, '', -1, 0, '', '', 'designtrace@designtrace', 'isModEnabled("designtrace")');
		//$result2=$extrafields->addExtraField('designtrace_myattr2', "New Attr 2 label", 'varchar', 1, 10, 'project',      0, 0, '', '', 1, '', -1, 0, '', '', 'designtrace@designtrace', 'isModEnabled("designtrace")');
		//$result3=$extrafields->addExtraField('designtrace_myattr3', "New Attr 3 label", 'varchar', 1, 10, 'bank_account', 0, 0, '', '', 1, '', -1, 0, '', '', 'designtrace@designtrace', 'isModEnabled("designtrace")');
		//$result4=$extrafields->addExtraField('designtrace_myattr4', "New Attr 4 label", 'select',  1,  3, 'thirdparty',   0, 1, '', array('options'=>array('code1'=>'Val1','code2'=>'Val2','code3'=>'Val3')), 1,'', -1, 0, '', '', 'designtrace@designtrace', 'isModEnabled("designtrace")');
		//$result5=$extrafields->addExtraField('designtrace_myattr5', "New Attr 5 label", 'text',    1, 10, 'user',         0, 0, '', '', 1, '', -1, 0, '', '', 'designtrace@designtrace', 'isModEnabled("designtrace")');

		// Permissions
		$this->remove($options);

		$sql = array();

		// Document templates
		$moduledir = dol_sanitizeFileName('designtrace');
		$myTmpObjects = array();
		$myTmpObjects['Design'] = array('includerefgeneration' => 0, 'includedocgeneration' => 0);

		foreach ($myTmpObjects as $myTmpObjectKey => $myTmpObjectArray) {
			if ($myTmpObjectArray['includerefgeneration']) {
				$src = DOL_DOCUMENT_ROOT.'/install/doctemplates/'.$moduledir.'/template_designs.odt';
				$dirodt = DOL_DATA_ROOT.($conf->entity > 1 ? '/'.$conf->entity : '').'/doctemplates/'.$moduledir;
				$dest = $dirodt.'/template_designs.odt';

				if (file_exists($src) && !file_exists($dest)) {
					require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
					dol_mkdir($dirodt);
					$result = dol_copy($src, $dest, '0', 0);
					if ($result < 0) {
						$langs->load("errors");
						$this->error = $langs->trans('ErrorFailToCopyFile', $src, $dest);
						return 0;
					}
				}

				$sql = array_merge($sql, array(
					"DELETE FROM ".$this->db->prefix()."document_model WHERE nom = 'standard_".$this->db->escape(strtolower($myTmpObjectKey))."' AND type = '".$this->db->escape(strtolower($myTmpObjectKey))."' AND entity = ".((int) $conf->entity),
					"INSERT INTO ".$this->db->prefix()."document_model (nom, type, entity) VALUES('standard_".$this->db->escape(strtolower($myTmpObjectKey))."', '".$this->db->escape(strtolower($myTmpObjectKey))."', ".((int) $conf->entity).")",
					"DELETE FROM ".$this->db->prefix()."document_model WHERE nom = 'generic_".$this->db->escape(strtolower($myTmpObjectKey))."_odt' AND type = '".$this->db->escape(strtolower($myTmpObjectKey))."' AND entity = ".((int) $conf->entity),
					"INSERT INTO ".$this->db->prefix()."document_model (nom, type, entity) VALUES('generic_".$this->db->escape(strtolower($myTmpObjectKey))."_odt', '".$this->db->escape(strtolower($myTmpObjectKey))."', ".((int) $conf->entity).")"
				));
			}
		}

		$result = $this->_init($sql, $options);
		if ($result > 0) {
			$this->createStarterLicences();
		}
		return $result;
	}

	/**
	 * Create the starter licence templates of the spec the first time the module is activated
	 * (when no licence template exists). Their wording is a placeholder: it must be written and
	 * checked by a lawyer before use. A failure is only logged.
	 *
	 * @return	void
	 */
	private function createStarterLicences()
	{
		global $user, $langs;

		$resql = $this->db->query("SELECT COUNT(rowid) as nb FROM ".$this->db->prefix()."designtrace_licence");
		$obj = $resql ? $this->db->fetch_object($resql) : null;
		if (!$obj || (int) $obj->nb > 0 || !is_object($user) || empty($user->id)) {
			return;
		}
		dol_include_once('/designtrace/class/licence.class.php');
		$langs->load('designtrace@designtrace');
		$wording = '<p><strong>'.$langs->transnoentities('LicenceStarterWording').'</strong></p><p>{customer} - {design} - {revision} - {scope} - {date}</p>';
		// personal, commercial, resell, modify, source, exclusive, attribution, portfolio
		$starters = array(
			'FULL' => array('LicenceStarterFull', array(1, 1, 1, 1, 1, 1, 0, 0), ''),
			'PERSONAL' => array('LicenceStarterPersonal', array(1, 0, 0, 0, 0, 0, 0, 1), ''),
			'COMMERCIAL' => array('LicenceStarterCommercial', array(1, 1, 1, 0, 0, 0, 0, 1), ''),
			'EXCLUSIVE' => array('LicenceStarterExclusive', array(1, 1, 1, 0, 0, 1, 0, 0), 'LicenceStarterExclusiveNote'),
			'PRINTS' => array('LicenceStarterPrints', array(1, 0, 0, 0, 0, 0, 0, 1), ''),
		);
		foreach ($starters as $code => $def) {
			$licence = new Licence($this->db);
			$licence->ref = $code;
			$licence->label = $langs->transnoentities($def[0]);
			$licence->description = ($def[2] ? $langs->transnoentities($def[2]) : '');
			$licence->context['nofirstversion'] = 1;
			if ($licence->create($user) <= 0) {
				dol_syslog(__METHOD__.' '.$licence->error, LOG_WARNING);
				continue;
			}
			$version = new LicenceVersion($this->db);
			$version->fk_licence = $licence->id;
			$version->wording = $wording;
			$version->change_note = $langs->transnoentities('LicenceStarterNote');
			foreach (LicenceVersion::getPermissionKeys() as $i => $key) {
				$version->$key = $def[1][$i];
			}
			if ($version->create($user) <= 0 || $version->activate($user) <= 0) {
				dol_syslog(__METHOD__.' '.$version->error, LOG_WARNING);
			}
		}
	}

	/**
	 *	Function called when module is disabled.
	 *	Remove from database constants, boxes and permissions from Dolibarr database.
	 *	Data directories are not deleted
	 *
	 *	@param	string		$options	Options when enabling module ('', 'noboxes')
	 *	@return	int<-1,1>				1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
