<?php if (!defined('TL_ROOT')) die('You cannot access this file directly!');

/**
 * Contao Open Source CMS
 * Copyright (C) 2005-2011 Leo Feyer
 *
 * Formerly known as TYPOlight Open Source CMS.
 *
 * This program is free software: you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation, either
 * version 3 of the License, or (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public
 * License along with this program. If not, please visit the Free
 * Software Foundation website at <http://www.gnu.org/licenses/>.
 *
 * PHP version 5
 * @copyright  Leo Feyer 2005-2011
 * @author     Leo Feyer <http://www.contao.org>
 * @package    System
 * @license    LGPL
 * @filesource
 */


/**
 * Class DC_Folder
 *
 * Provide methods to modify the file system.
 * @copyright  Leo Feyer 2005-2011
 * @author     Leo Feyer <http://www.contao.org>
 * @package    Controller
 */
class DC_Folder extends DataContainer implements listable, editable
{

	/**
	 * Current path
	 * @var string
	 */
	protected $strPath;

	/**
	 * Current file extension
	 * @var string
	 */
	protected $strExtension;

	/**
	 * Current filemounts
	 * @var array
	 */
	protected $arrFilemounts = array();

	/**
	 * Valid file types
	 * @var array
	 */
	protected $arrValidFileTypes = array();


	/**
	 * Initialize the object
	 * @param string
	 */
	public function __construct($strTable)
	{
		parent::__construct();
		$this->import('StringUtil','String');

		$this->intId = $this->Input->get('id', true);

		// Clear the clipboard
		if (isset($_GET['clipboard']))
		{
			$this->Session->set('CLIPBOARD', array());
			$this->redirect($this->getReferer());
		}

		// Check whether the table is defined
		if (!strlen($strTable) || !count($GLOBALS['TL_DCA'][$strTable]))
		{
			$this->log('Could not load data container configuration for "' . $strTable . '"', 'DC_Folder __construct()', TL_ERROR);
			trigger_error('Could not load data container configuration', E_USER_ERROR);
		}

		// Check permission to create new folders
		if ($this->Input->get('act') == 'paste' && $this->Input->get('mode') == 'create' && isset($GLOBALS['TL_DCA'][$strTable]['list']['new']))
		{
			$this->log('Attempt to create new folder although the method has been overwritten in the data container', 'DC_Folder __construct()', TL_ERROR);
			$this->redirect('contao/main.php?act=error');
		}

		// Set IDs and redirect
		if ($this->Input->post('FORM_SUBMIT') == 'tl_select')
		{
			$ids = deserialize($this->Input->post('IDS'));

			if (!is_array($ids) || count($ids) < 1)
			{
				$this->reload();
			}

			$session = $this->Session->getData();
			$session['CURRENT']['IDS'] = $ids;
			$this->Session->setData($session);

			if (isset($_POST['edit']))
			{
				$this->redirect(str_replace('act=select', 'act=editAll', $this->Environment->request));
			}
			elseif (isset($_POST['delete']))
			{
				$this->redirect(str_replace('act=select', 'act=deleteAll', $this->Environment->request));
			}
			elseif (isset($_POST['cut']) || isset($_POST['copy']))
			{
				$arrClipboard = $this->Session->get('CLIPBOARD');

				$arrClipboard[$strTable] = array
				(
					'id' => $ids,
					'mode' => (isset($_POST['cut']) ? 'cutAll' : 'copyAll')
				);

				$this->Session->set('CLIPBOARD', $arrClipboard);
				$this->redirect($this->getReferer());
			}
		}

		$this->strTable = $strTable;

		// Check for valid file types
		if ($GLOBALS['TL_DCA'][$this->strTable]['config']['validFileTypes'])
		{
			$this->arrValidFileTypes = trimsplit(',', $GLOBALS['TL_DCA'][$this->strTable]['config']['validFileTypes']);
		}

		// Call onload_callback (e.g. to check permissions)
		if (is_array($GLOBALS['TL_DCA'][$this->strTable]['config']['onload_callback']))
		{
			foreach ($GLOBALS['TL_DCA'][$this->strTable]['config']['onload_callback'] as $callback)
			{
				if (is_array($callback))
				{
					$this->import($callback[0]);
					$this->{$callback[0]}->{$callback[1]}($this);
				}
			}
		}

		// Get all filemounts (root folders)
		if (is_array($GLOBALS['TL_DCA'][$strTable]['list']['sorting']['root']))
		{
			$this->arrFilemounts = $this->eliminateNestedPaths($GLOBALS['TL_DCA'][$strTable]['list']['sorting']['root']);
		}
	}


	/**
	 * Return an object property
	 * @param string
	 * @return mixed
	 */
	public function __get($strKey)
	{
		switch ($strKey)
		{
			case 'path':
				return $this->strPath;
				break;

			case 'extension':
				return $this->strExtension;
				break;

			default:
				return parent::__get($strKey);
				break;
		}
	}


	/**
	 * List all files and folders of the file system
	 * @return string
	 */
	public function showAll()
	{
		$return = '';

		// Add to clipboard
		if ($this->Input->get('act') == 'paste')
		{
			if ($this->Input->get('mode') != 'create' && $this->Input->get('mode') != 'move')
			{
				$this->isValid($this->intId);
			}

			$arrClipboard = $this->Session->get('CLIPBOARD');

			$arrClipboard[$this->strTable] = array
			(
				'id' => $this->urlEncode($this->intId),
				'childs' => $this->Input->get('childs'),
				'mode' => $this->Input->get('mode')
			);

			$this->Session->set('CLIPBOARD', $arrClipboard);
		}

		// Get session data and toggle nodes
		if ($this->Input->get('tg') == 'all')
		{
			$session = $this->Session->getData();

			// Expand tree
			if (!is_array($session['filetree']) || count($session['filetree']) < 1 || current($session['filetree']) != 1)
			{
				$session['filetree'] = $this->getMD5Folders($GLOBALS['TL_CONFIG']['uploadPath']);
			}

			// Collapse tree
			else
			{
				$session['filetree'] = array();
			}

			$this->Session->setData($session);
			$this->redirect(preg_replace('/(&(amp;)?|\?)tg=[^& ]*/i', '', $this->Environment->request));
		}

		$blnClipboard = false;
		$arrClipboard = $this->Session->get('CLIPBOARD');

		// Check clipboard
		if (isset($arrClipboard[$this->strTable]) && count($arrClipboard[$this->strTable]))
		{
			$blnClipboard = true;
			$arrClipboard = $arrClipboard[$this->strTable];
		}

		$this->import('Files');

		// Call recursive function tree()
		if (!count($this->arrFilemounts) && !is_array($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['root']) && $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['root'] !== false)
		{
			$return .= $this->generateTree(TL_ROOT . '/' . $GLOBALS['TL_CONFIG']['uploadPath'], 0, false, false, ($blnClipboard ? $arrClipboard : false));
		}
		else
		{
			for ($i=0; $i<count($this->arrFilemounts); $i++)
			{
				if (is_dir(TL_ROOT . '/' . $this->arrFilemounts[$i]))
				{
					$return .= $this->generateTree(TL_ROOT . '/' . $this->arrFilemounts[$i], 0, true, false, ($blnClipboard ? $arrClipboard : false));
				}
			}
		}

		// Check for "create new" button
		$clsNew = 'header_new_folder';
		$lblNew = $GLOBALS['TL_LANG'][$this->strTable]['new'][0];
		$ttlNew = $GLOBALS['TL_LANG'][$this->strTable]['new'][1];
		$hrfNew = '&amp;act=paste&amp;mode=create';

		if (isset($GLOBALS['TL_DCA'][$this->strTable]['list']['new']))
		{
			$clsNew = $GLOBALS['TL_DCA'][$this->strTable]['list']['new']['class'];
			$lblNew = $GLOBALS['TL_DCA'][$this->strTable]['list']['new']['label'][0];
			$ttlNew = $GLOBALS['TL_DCA'][$this->strTable]['list']['new']['label'][1];
			$hrfNew = $GLOBALS['TL_DCA'][$this->strTable]['list']['new']['href'];
		}

		$imagePasteInto = $this->generateImage('pasteinto.gif', $GLOBALS['TL_LANG'][$this->strTable]['pasteinto'][0], 'class="blink"');

		// Build tree
		$return = '
<div id="tl_buttons">'.(($this->Input->get('act') == 'select') ? '
<a href="'.$this->getReferer(true).'" class="header_back" title="'.specialchars($GLOBALS['TL_LANG']['MSC']['backBT']).'" accesskey="b" onclick="Backend.getScrollOffset();">'.$GLOBALS['TL_LANG']['MSC']['backBT'].'</a>' : '') . (($this->Input->get('act') != 'select') ? '
<a href="'.$this->addToUrl($hrfNew).'" class="'.$clsNew.'" title="'.specialchars($ttlNew).'" accesskey="n" onclick="Backend.getScrollOffset();">'.$lblNew.'</a>' . (!$GLOBALS['TL_DCA'][$this->strTable]['config']['closed'] ? ' &nbsp; :: &nbsp; <a href="'.$this->addToUrl('&amp;act=paste&amp;mode=move').'" class="header_new" title="'.specialchars($GLOBALS['TL_LANG'][$this->strTable]['move'][1]).'" onclick="Backend.getScrollOffset();">'.$GLOBALS['TL_LANG'][$this->strTable]['move'][0].'</a>' : '') . $this->generateGlobalButtons(true) . ($blnClipboard ? ' &nbsp; :: &nbsp; <a href="'.$this->addToUrl('clipboard=1').'" class="header_clipboard" title="'.specialchars($GLOBALS['TL_LANG']['MSC']['clearClipboard']).'" accesskey="b">'.$GLOBALS['TL_LANG']['MSC']['clearClipboard'].'</a>' : '') : '') . '
</div>' . (($this->Input->get('act') == 'select') ? '

<form action="'.ampersand($this->Environment->request, true).'" id="tl_select" class="tl_form" method="post">
<div class="tl_formbody">
<input type="hidden" name="FORM_SUBMIT" value="tl_select" />' : '').'

<div class="tl_listing_container tree_view" id="tl_listing">'.(isset($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['breadcrumb']) ? $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['breadcrumb'] : '').(($this->Input->get('act') == 'select') ? '

<div class="tl_select_trigger">
<label for="tl_select_trigger" class="tl_select_label">'.$GLOBALS['TL_LANG']['MSC']['selectAll'].'</label> <input type="checkbox" id="tl_select_trigger" onclick="Backend.toggleCheckboxes(this)" class="tl_tree_checkbox" />
</div>' : '').'

<ul class="tl_listing">
  <li class="tl_folder_top" onmouseover="Theme.hoverDiv(this, 1);" onmouseout="Theme.hoverDiv(this, 0);"><div class="tl_left">'.$this->generateImage('filemounts.gif').' '.$GLOBALS['TL_LANG']['MSC']['filetree'].'</div> <div class="tl_right">'.(($blnClipboard && !count($this->arrFilemounts) && !is_array($GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['root']) && $GLOBALS['TL_DCA'][$this->strTable]['list']['sorting']['root'] !== false) ? '<a href="'.$this->addToUrl('&amp;act='.$arrClipboard['mode'].'&amp;mode=2&amp;pid='.$GLOBALS['TL_CONFIG']['uploadPath'].(!is_array($arrClipboard['id']) ? '&amp;id='.$arrClipboard['id'] : '')).'" title="'.specialchars($GLOBALS['TL_LANG'][$this->strTable]['pasteinto'][1]).'" onclick="Backend.getScrollOffset();">'.$imagePasteInto.'</a>' : '&nbsp;').'</div><div style="clear:both;"></div></li>'.$return.'
</ul>

</div>';

		// Close form
		if ($this->Input->get('act') == 'select')
		{
			$return .= '

<div class="tl_formbody_submit" style="text-align:right;">

<div class="tl_submit_container">
  <input type="submit" name="delete" id="delete" class="tl_submit" accesskey="d" onclick="return confirm(\''.$GLOBALS['TL_LANG']['MSC']['delAllConfirm'].'\');" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['deleteSelected']).'" />
  <input type="submit" name="cut" id="cut" class="tl_submit" accesskey="x" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['moveSelected']).'" />
  <input type="submit" name="copy" id="copy" class="tl_submit" accesskey="c" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['copySelected']).'" />' . (!$GLOBALS['TL_DCA'][$this->strTable]['config']['notEditable'] ? '
  <input type="submit" name="edit" id="edit" class="tl_submit" accesskey="s" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['editSelected']).'" />' : '') . '
</div>

</div>
</div>
</form>';
		}

		return $return;
	}


	/**
	 * Automatically switch to showAll
	 * @return string
	 */
	public function show()
	{
		return $this->showAll();
	}


	/**
	 * Create a new folder
	 * @param array
	 */
	public function create()
	{
		$this->import('Files');
		$strFolder = $this->Input->get('pid', true);

		if ($strFolder == '' || !file_exists(TL_ROOT . '/' . $strFolder) || !$this->isMounted($strFolder))
		{
			$this->log('Folder "'.$strFolder.'" was not mounted or is not a directory', 'DC_Folder create()', TL_ERROR);
			$this->redirect('contao/main.php?act=error');
		}

		// Empty clipboard
		$arrClipboard = $this->Session->get('CLIPBOARD');
		$arrClipboard[$this->strTable] = array();
		$this->Session->set('CLIPBOARD', $arrClipboard);

		$this->Files->mkdir($strFolder . '/__new__');
		$this->redirect(html_entity_decode($this->switchToEdit($strFolder . '/__new__')));
	}


	/**
	 * Move an existing file or folder
	 * @param boolean
	 */
	public function cut($blnDoNotRedirect=false)
	{
		$this->isValid($this->intId);
		$strFolder = $this->Input->get('pid', true);

		if (!file_exists(TL_ROOT . '/' . $this->intId) || !$this->isMounted($this->intId))
		{
			$this->log('File or folder "'.$this->intId.'" was not mounted or could not be found', 'DC_Folder cut()', TL_ERROR);
			$this->redirect('contao/main.php?act=error');
		}

		if (!file_exists(TL_ROOT . '/' . $strFolder) || !$this->isMounted($strFolder))
		{
			$this->log('Parent folder "'.$strFolder.'" was not mounted or is not a directory', 'DC_Folder cut()', TL_ERROR);
			$this->redirect('contao/main.php?act=error');
		}

		// Avoid a circular reference
		if (preg_match('/^' . preg_quote($this->intId, '/') . '/i', $strFolder))
		{
			$this->log('Attempt to move folder "'.$this->intId.'" to "'.$strFolder.'" (circular reference)', 'DC_Folder cut()', TL_ERROR);
			$this->redirect('contao/main.php?act=error');
		}

		// Empty clipboard
		$arrClipboard = $this->Session->get('CLIPBOARD');
		$arrClipboard[$this->strTable] = array();
		$this->Session->set('CLIPBOARD', $arrClipboard);

		$this->import('Files');

		$destination = str_replace(dirname($this->intId), $strFolder, $this->intId);
		$this->Files->rename($this->intId, $destination);
		$this->log('File or folder "' . $this->intId . '" has been moved to "' . $destination . '"', 'DC_Folder cut()', TL_FILES);

		if (!$blnDoNotRedirect)
		{
			$this->redirect($this->getReferer());
		}
	}


	/**
	 * Move all selected files and folders
	 */
	public function cutAll()
	{
		// PID is mandatory
		if (!strlen($this->Input->get('pid', true)))
		{
			$this->redirect($this->getReferer());
		}

		$arrClipboard = $this->Session->get('CLIPBOARD');

		if (isset($arrClipboard[$this->strTable]) && is_array($arrClipboard[$this->strTable]['id']))
		{
			foreach ($arrClipboard[$this->strTable]['id'] as $id)
			{
				$this->intId = urldecode($id);
				$this->cut(true);
			}
		}

		$this->redirect($this->getReferer());
	}


	/**
	 * Recursively duplicate files and folders
	 * @param string
	 * @param string
	 */
	public function copy($source='', $destination='')
	{
		$noReload = strlen($source);
		$strFolder = $this->Input->get('pid', true);

		if ($source == '')
		{
			$source = $this->intId;
		}

		if ($destination == '')
		{
			$destination = str_replace(dirname($source), $strFolder, $source);
		}

		$this->isValid($source);
		$this->isValid($destination);

		if (!file_exists(TL_ROOT . '/' . $source) || !$this->isMounted($source))
		{
			$this->log('File or folder "'.$source.'" was not mounted or could not be found', 'DC_Folder copy()', TL_ERROR);
			$this->redirect('contao/main.php?act=error');
		}

		if (!file_exists(TL_ROOT . '/' . $strFolder) || !$this->isMounted($strFolder))
		{
			$this->log('Parent folder "'.$strFolder.'" was not mounted or is not a directory', 'DC_Folder copy()', TL_ERROR);
			$this->redirect('contao/main.php?act=error');
		}

		// Avoid a circular reference
		if (preg_match('/^' . preg_quote($source, '/') . '/i', $strFolder))
		{
			$this->log('Attempt to copy folder "'.$source.'" to "'.$strFolder.'" (circular reference)', 'DC_Folder copy()', TL_ERROR);
			$this->redirect('contao/main.php?act=error');
		}

		// Empty clipboard
		$arrClipboard = $this->Session->get('CLIPBOARD');
		$arrClipboard[$this->strTable] = array();
		$this->Session->set('CLIPBOARD', $arrClipboard);

		$this->import('Files');

		// Copy folders
		if (is_dir(TL_ROOT . '/' . $source))
		{
			$new = $destination;
			$count = 1;

			while (is_dir(TL_ROOT . '/' . $new) && $count < 12)
			{
				$new = $destination . '_' . $count++;
			}

			$destination = $new;
			$this->Files->mkdir($destination);
			$files = scan(TL_ROOT . '/' . $source);

			foreach ($files as $file)
			{
				if (is_dir(TL_ROOT . '/' . $source .'/'. $file))
				{
					$this->copy($source . '/' . $file, $destination . '/' . $file);
				}
				else
				{
					$this->Files->copy($source . '/' . $file, $destination . '/' . $file);
				}
			}
		}

		// Copy file
		else
		{
			$new = $destination;
			$count = 1;

			while (file_exists(TL_ROOT . '/' . $new) && $count < 12)
			{
				$pif = pathinfo($destination);

				$new = str_replace
				(
					'.' . $pif['extension'],
					'_' . $count++ . '.' . $pif['extension'],
					$destination
				);
			}

			$destination = $new;
			$this->Files->copy($source, $destination);
		}

		// Do not reload on recursive calls
		if (!$noReload)
		{
			if (file_exists(TL_ROOT . '/' . $source) && $this->isMounted($source))
			{
				$this->log('File or folder "'.$source.'" has been duplicated', 'DC_Folder copy()', TL_FILES);
			}

			$this->redirect($this->getReferer());
		}
	}


	/**
	 * Move all selected files and folders
	 */
	public function copyAll()
	{
		// PID is mandatory
		if (!strlen($this->Input->get('pid', true)))
		{
			$this->redirect($this->getReferer());
		}

		$arrClipboard = $this->Session->get('CLIPBOARD');

		if (isset($arrClipboard[$this->strTable]) && is_array($arrClipboard[$this->strTable]['id']))
		{
			foreach ($arrClipboard[$this->strTable]['id'] as $id)
			{
				$this->copy(urldecode($id));
			}
		}

		$this->redirect($this->getReferer());
	}


	/**
	 * Recursively delete files and folders
	 * @param string
	 */
	public function delete($source='')
	{
		$noReload = strlen($source);

		if ($source == '')
		{
			$source = $this->intId;
		}

		$this->isValid($source);

		// Delete file or folder
		if (!file_exists(TL_ROOT . '/' . $source) || !$this->isMounted($source))
		{
			$this->log('File or folder "'.$source.'" was not mounted or could not be found', 'DC_Folder delete()', TL_ERROR);
			$this->redirect('contao/main.php?act=error');
		}

		$this->import('Files');

		// Delete folders
		if (is_dir(TL_ROOT . '/' . $source))
		{
			$files = scan(TL_ROOT . '/' . $source);

			foreach ($files as $file)
			{
				if (is_dir(TL_ROOT . '/' . $source . '/' . $file))
				{
					$this->delete($source . '/' . $file);
				}
				else
				{
					$this->Files->delete($source . '/' . $file);
				}
			}

			$this->Files->rmdir($source);
		}

		// Delete file
		else
		{
			$this->Files->delete($source);
		}

		// Do not reload on recursive calls
		if (!$noReload)
		{
			$this->log('File or folder "' . str_replace(TL_ROOT.'/', '', $source) . '" has been deleted', 'DC_Folder delete()', TL_FILES);
			$this->redirect($this->getReferer());
		}
	}


	/**
	 * Delete all files and folders that are currently shown
	 */
	public function deleteAll()
	{
		$session = $this->Session->getData();
		$ids = $session['CURRENT']['IDS'];

		if (is_array($ids) && strlen($ids[0]))
		{
			foreach ($ids as $id)
			{
				$this->delete(urldecode($id));
			}
		}

		$this->redirect($this->getReferer());
	}


	/**
	 * Automatically switch to showAll
	 * @return string
	 */
	public function undo()
	{
		return $this->showAll();
	}


	/**
	 * Move one or more local files to the server
	 * @param boolean
	 */
	public function move($blnIsAjax=false)
	{
		$error = false;
		$strFolder = $this->Input->get('pid', true);

		if (!file_exists(TL_ROOT . '/' . $strFolder) || !$this->isMounted($strFolder))
		{
			$this->log('Folder "'.$strFolder.'" was not mounted or is not a directory', 'DC_Folder move()', TL_ERROR);
			$this->redirect('contao/main.php?act=error');
		}

		if (!preg_match('/^'.preg_quote($GLOBALS['TL_CONFIG']['uploadPath'], '/').'/i', $strFolder))
		{
			$this->log('Parent folder "'.$strFolder.'" is not within the files directory', 'DC_Folder move()', TL_ERROR);
			$this->redirect('contao/main.php?act=error');
		}

		// Empty clipboard
		if (!$blnIsAjax)
		{
			$arrClipboard = $this->Session->get('CLIPBOARD');
			$arrClipboard[$this->strTable] = array();
			$this->Session->set('CLIPBOARD', $arrClipboard);
		}

		$arrUploaded = array();
		$uploadTypes = trimsplit(',', strtolower($GLOBALS['TL_CONFIG']['uploadTypes']));

		// Upload files
		if ($this->Input->post('FORM_SUBMIT') == 'tl_upload')
		{
			$maxlength_kb = $this->getReadableSize($GLOBALS['TL_CONFIG']['maxFileSize']);

			foreach ($_FILES as $file)
			{
				// Romanize the filename
				$file['name'] = strip_tags($file['name']);
				$file['name'] = utf8_romanize($file['name']);
				$file['name'] = str_replace('"', '', $file['name']);

				// File was not uploaded
				if (!is_uploaded_file($file['tmp_name']))
				{
					if (in_array($file['error'], array(1, 2)))
					{
						$_SESSION['TL_ERROR'][] = sprintf($GLOBALS['TL_LANG']['ERR']['filesize'], $maxlength_kb);
						$this->log('File "'.$file['name'].'" exceeds the maximum file size of '.$maxlength_kb, 'DC_Folder move()', TL_ERROR);

						$error = true;
					}

					if ($file['error'] == 3)
					{
						$_SESSION['TL_ERROR'][] = sprintf($GLOBALS['TL_LANG']['ERR']['filepartial'], $file['name']);
						$this->log('File "'.$file['name'].'" was only partially uploaded' , 'DC_Folder move()', TL_ERROR);

						$error = true;
					}

					continue;
				}

				// File is too big
				if ($file['size'] > $GLOBALS['TL_CONFIG']['maxFileSize'])
				{
					$_SESSION['TL_ERROR'][] = sprintf($GLOBALS['TL_LANG']['ERR']['filesize'], $maxlength_kb);
					$this->log('File "'.$file['name'].'" exceeds the maximum file size of '.$maxlength_kb, 'DC_Folder move()', TL_ERROR);

					$error = true;
					continue;
				}

				$pathinfo = pathinfo($file['name']);

				// File type not allowed
				if (!in_array(strtolower($pathinfo['extension']), $uploadTypes))
				{
					$_SESSION['TL_ERROR'][] = sprintf($GLOBALS['TL_LANG']['ERR']['filetype'], $pathinfo['extension']);
					$this->log('File type "'.$pathinfo['extension'].'" is not allowed to be uploaded ('.$file['name'].')', 'DC_Folder move()', TL_ERROR);

					$error = true;
					continue;
				}

				$this->import('Files');
				$strNewFile = $strFolder . '/' . $file['name'];

				// Move file to destination
				if ($this->Files->move_uploaded_file($file['tmp_name'], $strNewFile))
				{
					$blnExceeds = false;
					$blnResized = false;

					$this->Files->chmod($strNewFile, 0644);

					// Resize image if necessary
					if (($arrImageSize = @getimagesize(TL_ROOT . '/' . $strNewFile)) !== false)
					{
						// Image is too big
						if ($arrImageSize[0] > 3000 || $arrImageSize[1] > 3000)
						{
							$blnExceeds = true;
						}
						else
						{
							// Image exceeds maximum image width
							if ($arrImageSize[0] > $GLOBALS['TL_CONFIG']['imageWidth'])
							{
								$blnResized = true;
								$this->resizeImage($strNewFile, $GLOBALS['TL_CONFIG']['imageWidth'], 0);

								// Recalculate image size
								$arrImageSize = @getimagesize(TL_ROOT . '/' . $strNewFile);
							}

							// Image exceeds maximum image height
							if ($arrImageSize[1] > $GLOBALS['TL_CONFIG']['imageHeight'])
							{
								$blnResized = true;
								$this->resizeImage($strNewFile, 0, $GLOBALS['TL_CONFIG']['imageHeight']);
							}
						}
					}

					$arrUploaded[] = $strNewFile;

					// Notify user
					if ($blnExceeds)
					{
						$_SESSION['TL_INFO'][] = sprintf($GLOBALS['TL_LANG']['MSC']['fileExceeds'], $file['name']);
						$this->log('File "'.$file['name'].'" uploaded successfully but was too big to be resized automatically', 'DC_Folder move()', TL_FILES);
					}
					elseif ($blnResized)
					{
						$_SESSION['TL_INFO'][] = sprintf($GLOBALS['TL_LANG']['MSC']['fileResized'], $file['name']);
						$this->log('File "'.$file['name'].'" uploaded successfully and was scaled down to the maximum dimensions', 'DC_Folder move()', TL_FILES);
					}
					else
					{
						$_SESSION['TL_CONFIRM'][] = sprintf($GLOBALS['TL_LANG']['MSC']['fileUploaded'], $file['name']);
						$this->log('File "'.$file['name'].'" uploaded successfully', 'DC_Folder move()', TL_FILES);
					}
				}
			}

			// HOOK: post upload callback
			if (isset($GLOBALS['TL_HOOKS']['postUpload']) && is_array($GLOBALS['TL_HOOKS']['postUpload']))
			{
				foreach ($GLOBALS['TL_HOOKS']['postUpload'] as $callback)
				{
					$this->import($callback[0]);
					$this->{$callback[0]}->{$callback[1]}($arrUploaded);
				}
			}

			// Send FancyUpload response
			if ($blnIsAjax)
			{
				if ($error)
				{
					echo json_encode(array('status'=>'0', 'message'=>$_SESSION['TL_ERROR'][0]));
				}
				elseif ($blnExceeds || $blnResized)
				{
					echo json_encode(array('status'=>'1', 'message'=>$_SESSION['TL_INFO'][0]));
				}
				else
				{
					echo json_encode(array('status'=>'1', 'message'=>$_SESSION['TL_CONFIRM'][0]));
				}

				$_SESSION['TL_ERROR'] = array();
				$_SESSION['TL_INFO'] = array();
				$_SESSION['TL_CONFIRM'] = array();

				exit;
			}

			// Redirect or reload
			elseif (!$error)
			{
				$this->import('Automator');
				$this->Automator->purgeHtmlFolder();

				if ($this->Input->post('uploadNback') && !$blnResized)
				{
					$_SESSION['TL_INFO'] = '';
					$_SESSION['TL_ERROR'] = '';
					$_SESSION['TL_CONFIRM'] = '';

					$this->redirect($this->getReferer());
				}

				$this->reload();
			}
		}

		$fields = '';

		// Upload fields
		for ($i=0; $i<$GLOBALS['TL_CONFIG']['uploadFields']; $i++)
		{
			$fields .= '
  <input type="file" name="'.$i.'" class="tl_upload_field" maxlength="'.$GLOBALS['TL_CONFIG']['maxFileSize'].'" onfocus="Backend.getScrollOffset();" /><br />';
		}

		$strFancyUpload = '';

		// Add FancyUpload scripts
		if ($GLOBALS['TL_CONFIG']['fancyUpload'])
		{
			$GLOBALS['TL_CSS'][] = 'plugins/fancyupload/css/fancyupload.css?'. FANCYUPLOAD . '|screen';
			$GLOBALS['TL_JAVASCRIPT'][] = 'plugins/fancyupload/js/fancyupload.js?' . FANCYUPLOAD;

			$fancy = new stdClass();

			// Add upload types and key
			$fancy->uploadTypes = $uploadTypes;
			$fancy->script = basename($this->Environment->script);
			$fancy->isPopup = ($fancy->script == 'files.php') ? 'true' : 'false';

			// Add labels
			foreach ($GLOBALS['TL_LANG']['tl_files'] as $k=>$v)
			{
				list($prefix, $key) = explode('_', $k);

				if ($prefix == 'fancy')
				{
					$fancy->$key = $v;
				}
			}

			// Set upload script
			$uploadScript = sprintf('%s/system/config/%s.php', TL_ROOT, basename($GLOBALS['TL_DCA'][$this->strTable]['config']['uploadScript']));

			ob_start();
			require($uploadScript);
			$strFancyUpload = ob_get_contents();
			ob_end_clean();
		}

		// Display upload form
		return '
<div id="tl_buttons">
<a href="'.$this->getReferer(true).'" class="header_back" title="'.specialchars($GLOBALS['TL_LANG']['MSC']['backBT']).'" accesskey="b" onclick="Backend.getScrollOffset();">'.$GLOBALS['TL_LANG']['MSC']['backBT'].'</a>
</div>

<h2 class="sub_headline">'.sprintf($GLOBALS['TL_LANG']['tl_files']['uploadFF'], basename($strFolder)).'</h2>'.$this->getMessages().'

<form action="'.ampersand($this->Environment->request, true).'" id="'.$this->strTable.'" class="tl_form" method="post"'.(count($this->onsubmit) ? ' onsubmit="'.implode(' ', $this->onsubmit).'"' : '').' enctype="multipart/form-data">
<div class="tl_formbody_edit">
<input type="hidden" name="FORM_SUBMIT" value="tl_upload" />
<input type="hidden" name="MAX_FILE_SIZE" value="'.$GLOBALS['TL_CONFIG']['maxFileSize'].'" />

<div class="tl_tbox block">
  <h3>'.$GLOBALS['TL_LANG'][$this->strTable]['fileupload'][0].'</h3>
  <div id="fancy-fallback">'.$fields.'
  </div>
  <div id="fancy-status" class="fancy-hide">
  <p><a href="#" id="fancy-browse">'.$GLOBALS['TL_LANG']['tl_files']['browseFiles'].'</a> | <a href="#" id="fancy-clear">'.$GLOBALS['TL_LANG']['tl_files']['clearList'].'</a> | <a href="#" id="fancy-upload">'.$GLOBALS['TL_LANG']['tl_files']['startUpload'].'</a></p>
  <div>
    <strong class="overall-title"></strong><br />
    <img src="plugins/fancyupload/assets/bar.gif" alt="" class="progress overall-progress" />
  </div>
  <div style="margin-top:3px">
    <strong class="current-title"></strong><br />
    <img src="plugins/fancyupload/assets/bar.gif" alt="" class="progress current-progress" />
  </div>
  <div class="current-text"></div>
  </div>
  <ul id="fancy-list" class="fancy-hide">
    <li></li>
  </ul>'.(strlen($GLOBALS['TL_LANG'][$this->strTable]['fileupload'][1]) ? '
  <p class="tl_help tl_tip">'.$GLOBALS['TL_LANG'][$this->strTable]['fileupload'][1].'</p>' : '').'
</div>

</div>

<div id="fancy-submit" class="tl_formbody_submit">

<div class="tl_submit_container">
<input type="submit" name="upload" class="tl_submit" accesskey="s" value="'.specialchars($GLOBALS['TL_LANG'][$this->strTable]['upload']).'" />
<input type="submit" name="uploadNback" class="tl_submit" accesskey="c" value="'.specialchars($GLOBALS['TL_LANG'][$this->strTable]['uploadNback']).'" />
</div>

</div>
' . $strFancyUpload . '
</form>';
	}


	/**
	 * Autogenerate a form to rename a file or folder
	 * @return string
	 */
	public function edit()
	{
		$return = '';
		$this->noReload = false;
		$this->isValid($this->intId);

		if (!file_exists(TL_ROOT . '/' . $this->intId) || !$this->isMounted($this->intId))
		{
			$this->log('File or folder "'.$this->intId.'" was not mounted or could not be found', 'DC_Folder edit()', TL_ERROR);
			$this->redirect('contao/main.php?act=error');
		}

		// Build an array from boxes and rows (do not show excluded fields)
		$this->strPalette = $this->getPalette();
		$boxes = trimsplit(';', $this->strPalette);

		if (count($boxes))
		{
			// Get fields
			foreach ($boxes as $k=>$v)
			{
				$boxes[$k] = trimsplit(',', $v);

				foreach ($boxes[$k] as $kk=>$vv)
				{
					if ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$vv]['exclude'] || !count($GLOBALS['TL_DCA'][$this->strTable]['fields'][$vv]))
					{
						unset($boxes[$k][$kk]);
					}
				}

				// Unset a box if it does not contain any fields
				if (count($boxes[$k]) < 1)
				{
					unset($boxes[$k]);
				}
			}

			// Render boxes
			$class = 'tl_tbox block';

			foreach ($boxes as $k=>$v)
			{
				$return .= '
<div class="'.$class.'">';

				// Build rows of the current box
				foreach ($v as $kk=>$vv)
				{
					$this->strField = $vv;
					$this->strInputName = $vv;

					// Load current value
					$pathinfo = pathinfo($this->intId);
					$this->strPath = $pathinfo['dirname'];

					if (is_dir(TL_ROOT . '/' . $this->intId))
					{
						$this->strExtension = '';
						$this->varValue = basename($pathinfo['basename']);
					}
					else
					{
						$this->strExtension = strlen($pathinfo['extension']) ? '.'.$pathinfo['extension'] : '';
						$this->varValue = basename($pathinfo['basename'], $this->strExtension);
					}

					// Fix Unix system files like .htaccess
					if (strncmp($this->varValue, '.', 1) === 0)
					{
						$this->strExtension = '';
					}

					// Clear the current value if it is a new folder
					if ($this->Input->post('FORM_SUBMIT') != 'tl_files' && $this->Input->post('FORM_SUBMIT') != 'tl_templates' && $this->varValue == '__new__')
					{
						$this->varValue = '';
					}

					// Call load_callback
					if (is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['load_callback']))
					{
						foreach ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['load_callback'] as $callback)
						{
							if (is_array($callback))
							{
								$this->import($callback[0]);
								$this->varValue = $this->{$callback[0]}->{$callback[1]}($this->varValue, $this);
							}
						}
					}

					// Build row
					$return .= $this->row();
				}

				$class = 'tl_box block';
				$return .= '
  <input type="hidden" name="FORM_FIELDS[]" value="'.specialchars($this->strPalette).'" />
</div>';
			}
		}

		// Add some buttons and end the form
		$return .= '
</div>

<div class="tl_formbody_submit">

<div class="tl_submit_container">
<input type="submit" name="save" id="save" class="tl_submit" accesskey="s" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['save']).'" />
<input type="submit" name="saveNclose" id="saveNclose" class="tl_submit" accesskey="c" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['saveNclose']).'" />
</div>

</div>
</form>';

		// Begin the form (-> DO NOT CHANGE THIS ORDER -> this way the onsubmit attribute of the form can be changed by a field)
		$return = '
<div id="tl_buttons">
<a href="'.$this->getReferer(true).'" class="header_back" title="'.specialchars($GLOBALS['TL_LANG']['MSC']['backBT']).'" accesskey="b" onclick="Backend.getScrollOffset();">'.$GLOBALS['TL_LANG']['MSC']['backBT'].'</a>
</div>

<h2 class="sub_headline">'.$GLOBALS['TL_LANG']['tl_files']['editFF'].'</h2>'.$this->getMessages().'

<form action="'.ampersand($this->Environment->request, true).'" id="'.$this->strTable.'" class="tl_form" method="post"'.(count($this->onsubmit) ? ' onsubmit="'.implode(' ', $this->onsubmit).'"' : '').'>
<div class="tl_formbody_edit">
<input type="hidden" name="FORM_SUBMIT" value="'.specialchars($this->strTable).'" />'.($this->noReload ? '
<p class="tl_error">'.$GLOBALS['TL_LANG']['ERR']['general'].'</p>' : '').$return;

		// Reload the page to prevent _POST variables from being sent twice
		if ($this->Input->post('FORM_SUBMIT') == $this->strTable && !$this->noReload)
		{
			// Call onsubmit_callback
			if (is_array($GLOBALS['TL_DCA'][$this->strTable]['config']['onsubmit_callback']))
			{
				foreach ($GLOBALS['TL_DCA'][$this->strTable]['config']['onsubmit_callback'] as $callback)
				{
					$this->import($callback[0]);
					$this->{$callback[0]}->{$callback[1]}($this);
				}
			}

			// Reload
			if ($this->Input->post('saveNclose'))
			{
				$_SESSION['TL_INFO'] = '';
				$_SESSION['TL_ERROR'] = '';
				$_SESSION['TL_CONFIRM'] = '';

				setcookie('BE_PAGE_OFFSET', 0, 0, '/');
				$this->redirect($this->getReferer());
			}

			$this->redirect($this->addToUrl('id='.$this->urlEncode($this->strPath.'/'.$this->varValue).$this->strExtension));
		}

		// Set the focus if there is an error
		if ($this->noReload)
		{
			$return .= '

<script type="text/javascript">
<!--//--><![CDATA[//><!--
window.addEvent(\'domready\', function()
{
    Backend.vScrollTo(($(\'' . $this->strTable . '\').getElement(\'label.error\').getPosition().y - 20));
});
//--><!]]>
</script>';
		}

		return $return;
	}


	/**
	 * Autogenerate a form to edit all records that are currently shown
	 * @param integer
	 * @param integer
	 * @return string
	 */
	public function editAll()
	{
		$return = '';

		if ($GLOBALS['TL_DCA'][$this->strTable]['config']['notEditable'])
		{
			$this->log('Table ' . $this->strTable . ' is not editable', 'DC_Folder editAll()', TL_ERROR);
			$this->redirect('contao/main.php?act=error');
		}

		// Get current IDs from session
		$session = $this->Session->getData();
		$ids = $session['CURRENT']['IDS'];

		// Save field selection in session
		if ($this->Input->post('FORM_SUBMIT') == $this->strTable.'_all' && $this->Input->get('fields'))
		{
			$session['CURRENT'][$this->strTable] = deserialize($this->Input->post('all_fields'));
			$this->Session->setData($session);
		}

		$fields = $session['CURRENT'][$this->strTable];

		// Add fields
		if (is_array($fields) && count($fields) && $this->Input->get('fields'))
		{
			$class = 'tl_tbox block';

			// Walk through each record
			foreach ($ids as $id)
			{
				$this->intId = md5($id);
				$this->strPalette = trimsplit('[;,]', $this->getPalette());

				$return .= '
<div class="'.$class.'">';

				$class = 'tl_box block';
				$formFields = array();

				foreach ($this->strPalette as $v)
				{
					// Check whether field is excluded
					if ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['exclude'])
					{
						continue;
					}

					if (!in_array($v, $fields))
					{
						continue;
					}

					$this->strField = $v;
					$this->strInputName = $v.'_'.$this->intId;
					$formFields[] = $v.'_'.$this->intId;

					// Load current value
					$pathinfo = pathinfo(urldecode($id));

					$this->strPath = $pathinfo['dirname'];
					$this->strExtension = strlen($pathinfo['extension']) ? '.'.$pathinfo['extension'] : '';
					$this->varValue = basename($pathinfo['basename'], $this->strExtension);

					// Fix Unix system files like .htaccess
					if (strncmp($this->varValue, '.', 1) === 0)
					{
						$this->strExtension = '';
					}

					// Call load_callback
					if (is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['load_callback']))
					{
						foreach ($GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField]['load_callback'] as $callback)
						{
							$this->import($callback[0]);
							$this->varValue = $this->{$callback[0]}->{$callback[1]}($this->varValue, $this);
						}
					}

					// Build the current row
					$return .= $this->row();
				}

				// Close box
				$return .= '
  <input type="hidden" name="FORM_FIELDS_'.$this->intId.'[]" value="'.specialchars(implode(',', $formFields)).'" />
</div>';
			}

			// Add the form
			$return = '

<h2 class="sub_headline_all">'.sprintf($GLOBALS['TL_LANG']['MSC']['all_info'], $this->strTable).'</h2>

<form action="'.ampersand($this->Environment->request, true).'" id="'.$this->strTable.'" class="tl_form" method="post">
<div class="tl_formbody_edit">
<input type="hidden" name="FORM_SUBMIT" value="'.$this->strTable.'" />'.($this->noReload ? '

<p class="tl_error">'.$GLOBALS['TL_LANG']['ERR']['general'].'</p>' : '').$return.'

</div>

<div class="tl_formbody_submit">

<div class="tl_submit_container">
<input type="submit" name="save" id="save" class="tl_submit" accesskey="s" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['save']).'" />
<input type="submit" name="saveNclose" id="saveNclose" class="tl_submit" accesskey="c" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['saveNclose']).'" />
</div>

</div>
</form>';

			// Set the focus if there is an error
			if ($this->noReload)
			{
				$return .= '

<script type="text/javascript">
<!--//--><![CDATA[//><!--
window.addEvent(\'domready\', function()
{
    Backend.vScrollTo(($(\'' . $this->strTable . '\').getElement(\'label.error\').getPosition().y - 20));
});
//--><!]]>
</script>';
			}

			// Reload the page to prevent _POST variables from being sent twice
			if ($this->Input->post('FORM_SUBMIT') == $this->strTable && !$this->noReload)
			{
				if ($this->Input->post('saveNclose'))
				{
					setcookie('BE_PAGE_OFFSET', 0, 0, '/');
					$this->redirect($this->getReferer());
				}

				$this->reload();
			}
		}

		// Else show a form to select the fields
		else
		{
			$options = '';
			$fields = array();

			// Add fields of the current table
			$fields = array_merge($fields, array_keys($GLOBALS['TL_DCA'][$this->strTable]['fields']));

			// Show all non-excluded fields
			foreach ($fields as $field)
			{
				if (!$GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['exclude'] && !$GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['eval']['doNotShow'] && (strlen($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['inputType']) || is_array($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['input_field_callback'])))
				{
					$options .= '
<input type="checkbox" name="all_fields[]" id="all_'.$field.'" class="tl_checkbox" value="'.specialchars($field).'" /> <label for="all_'.$field.'" class="tl_checkbox_label">'.(strlen($GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label'][0]) ? $GLOBALS['TL_DCA'][$this->strTable]['fields'][$field]['label'][0] : $GLOBALS['TL_LANG']['MSC'][$field][0]).'</label><br />';
				}
			}

			// Return select menu
			$return .= (($_POST && !count($_POST['all_fields'])) ? '

<p class="tl_error">'.$GLOBALS['TL_LANG']['ERR']['general'].'</p>' : '').'

<h2 class="sub_headline_all">'.sprintf($GLOBALS['TL_LANG']['MSC']['all_info'], $this->strTable).'</h2>

<form action="'.ampersand($this->Environment->request, true).'&amp;fields=1" id="'.$this->strTable.'_all" class="tl_form" method="post">
<div class="tl_formbody_edit">
<input type="hidden" name="FORM_SUBMIT" value="'.$this->strTable.'_all" />

<div class="tl_tbox block">
<h3><label for="fields">'.$GLOBALS['TL_LANG']['MSC']['all_fields'][0].'</label></h3>'.(($_POST && !count($_POST['all_fields'])) ? '
<p class="tl_error">'.$GLOBALS['TL_LANG']['ERR']['all_fields'].'</p>' : '').'
<div id="fields" class="tl_checkbox_container">
<input type="checkbox" id="check_all" class="tl_checkbox" onclick="Backend.toggleCheckboxes(this);" /> <label for="check_all" style="color:#a6a6a6;"><em>'.$GLOBALS['TL_LANG']['MSC']['selectAll'].'</em></label><br />'.$options.'
</div>'.(($GLOBALS['TL_CONFIG']['showHelp'] && strlen($GLOBALS['TL_LANG']['MSC']['all_fields'][1])) ? '
<p class="tl_help tl_tip">'.$GLOBALS['TL_LANG']['MSC']['all_fields'][1].'</p>' : '').'
</div>

</div>

<div class="tl_formbody_submit">

<div class="tl_submit_container">
<input type="submit" name="save" id="save" class="tl_submit" accesskey="s" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['continue']).'" />
</div>

</div>
</form>';
		}

		// Return
		return '
<div id="tl_buttons">
<a href="'.$this->getReferer(true).'" class="header_back" title="'.specialchars($GLOBALS['TL_LANG']['MSC']['backBT']).'" accesskey="b" onclick="Backend.getScrollOffset();">'.$GLOBALS['TL_LANG']['MSC']['backBT'].'</a>
</div>'.$return;
	}


	/**
	 * Load the source editor
	 * @return string
	 */
	public function source()
	{
		$this->isValid($this->intId);

		if (is_dir(TL_ROOT .'/'. $this->intId))
		{
			$this->log('Directory "'.$this->intId.'" cannot be edited', 'DC_Folder source()', TL_ERROR);
			$this->redirect('contao/main.php?act=error');
		}
		elseif (!file_exists(TL_ROOT .'/'. $this->intId))
		{
			$this->log('File "'.$this->intId.'" does not exist', 'DC_Folder source()', TL_ERROR);
			$this->redirect('contao/main.php?act=error');
		}

		$this->import('BackendUser', 'User');

		// Check user permission
		if (!$this->User->isAdmin && !$this->User->hasAccess('f5', 'fop'))
		{
			$this->log('Not enough permissions to edit file source of file "'.$this->intId.'"', 'DC_Folder source()', TL_ERROR);
			$this->redirect('contao/main.php?act=error');
		}

		$objFile = new File($this->intId);

		// Check whether file type is editable
		if (!in_array($objFile->extension, trimsplit(',', $GLOBALS['TL_CONFIG']['editableFiles'])))
		{
			$this->log('File type "'.$objFile->extension.'" ('.$this->intId.') is not allowed to be edited', 'DC_Folder source()', TL_ERROR);
			$this->redirect('contao/main.php?act=error');
		}

		$strContent = $objFile->getContent();

		// Process request
		if ($this->Input->post('FORM_SUBMIT') == 'tl_files')
		{
			// Save file
			if (md5($strContent) != md5($this->Input->postRaw('source')))
			{
				$objFile->write($this->Input->postRaw('source'));
				$objFile->close();
			}

			if ($this->Input->post('saveNclose'))
			{
				setcookie('BE_PAGE_OFFSET', 0, 0, '/');
				$this->redirect($this->getReferer());
			}

			$this->reload();
		}

		$editArea = '';

		// Prepare the code editor
		if ($GLOBALS['TL_CONFIG']['useCE'])
		{
			$this->ceFields = array('ctrl_source');
			$this->ceField = 'ctrl_source'; // Backwards compatibility
			$this->language = $GLOBALS['TL_LANGUAGE'];
			$this->extension = $objFile->extension;

			// Load the code editor configuration
			ob_start();
			include(TL_ROOT . '/system/config/editArea.php');
			$editArea = ob_get_contents();
			ob_end_clean();
		}

		return'
<div id="tl_buttons">
<a href="'.$this->getReferer(true).'" class="header_back" title="'.specialchars($GLOBALS['TL_LANG']['MSC']['backBT']).'" accesskey="b" onclick="Backend.getScrollOffset();">'.$GLOBALS['TL_LANG']['MSC']['backBT'].'</a>
</div>

<h2 class="sub_headline">'.sprintf($GLOBALS['TL_LANG']['tl_files']['editFile'], $objFile->basename).'</h2>'.$this->getMessages().'

<form action="'.ampersand($this->Environment->request, true).'" id="tl_files" class="tl_form" method="post">
<div class="tl_formbody_edit">
<input type="hidden" name="FORM_SUBMIT" value="tl_files" />
<div class="tl_tbox block">
  <h3><label for="ctrl_source">'.$GLOBALS['TL_LANG']['tl_files']['editor'][0].'</label></h3>
  <textarea name="source" id="ctrl_source" class="tl_textarea monospace" rows="12" cols="80" style="height:400px;" onfocus="Backend.getScrollOffset();">' . "\n" . htmlspecialchars($strContent) . '</textarea>' . (($GLOBALS['TL_CONFIG']['showHelp'] && strlen($GLOBALS['TL_LANG']['tl_files']['editor'][1])) ? '
  <p class="tl_help tl_tip">'.$GLOBALS['TL_LANG']['tl_files']['editor'][1].'</p>' : '') . '
</div>
</div>

<div class="tl_formbody_submit">

<div class="tl_submit_container">
<input type="submit" name="save" id="save" class="tl_submit" accesskey="s" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['save']).'" />
<input type="submit" name="saveNclose" id="saveNclose" class="tl_submit" accesskey="c" value="'.specialchars($GLOBALS['TL_LANG']['MSC']['saveNclose']).'" />
</div>

</div>
</form>' . "\n\n" . $editArea;
	}


	/**
	 * Protect a folder
	 * @return string
	 */
	public function protect()
	{
		if (!is_dir(TL_ROOT . '/' . $this->intId))
		{
			$this->log('Resource "' . $this->intId . '" is not a directory', 'DC_Folder protect()', TL_ERROR);
			$this->redirect('contao/main.php?act=error');
		}

		// Remove protection
		if (file_exists(TL_ROOT . '/' . $this->intId . '/.htaccess'))
		{
			$objFile = new File($this->intId . '/.htaccess');
			$objFile->delete();

			$this->log('Protection from folder "' . $this->intId . '" has been removed', 'DC_Folder protect()', TL_FILES);
			$this->redirect($this->getReferer());
		}

		// Protect folder
		else
		{
			$objFile = new File($this->intId . '/.htaccess');
			$objFile->write("order deny,allow\ndeny from all");
			$objFile->close();

			$this->log('Folder "' . $this->intId . '" has been protected', 'DC_Folder protect()', TL_FILES);
			$this->redirect($this->getReferer());
		}
	}


	/**
	 * Save the current value
	 * @param mixed
	 * @throws Exception
	 */
	protected function save($varValue)
	{
		if ($this->Input->post('FORM_SUBMIT') != $this->strTable || !file_exists(TL_ROOT . '/' . $this->strPath . '/' . $this->varValue . $this->strExtension) || !$this->isMounted($this->strPath . '/' . $this->varValue . $this->strExtension) || $this->varValue == $varValue)
		{
			return;
		}

		$this->import('Files');
		$arrData = $GLOBALS['TL_DCA'][$this->strTable]['fields'][$this->strField];
		$varValue = utf8_romanize($varValue);

		// Call save_callback
		if (is_array($arrData['save_callback']))
		{
			foreach ($arrData['save_callback'] as $callback)
			{
				$this->import($callback[0]);
				$varValue = $this->{$callback[0]}->{$callback[1]}($varValue, $this);
			}
		}

		$this->Files->rename($this->strPath . '/' . $this->varValue . $this->strExtension, $this->strPath . '/' . $varValue . $this->strExtension);

		// Add a log entry
		if (stristr($this->intId, '__new__') == true)
		{
			$this->log('Folder "'.$this->strPath.'/'.$varValue.$this->strExtension.'" has been created', 'DC_Folder save()', TL_FILES);
		}
		else
		{
			$this->log('File or folder "'.$this->strPath.'/'.$this->varValue.$this->strExtension.'" has been renamed to "'.$this->strPath.'/'.$varValue.$this->strExtension.'"', 'DC_Folder save()', TL_FILES);
		}

		// Set the new value so the input field can show it
		if ($this->Input->get('act') == 'editAll')
		{
			$session = $this->Session->getData();

			if (($index = array_search($this->urlEncode($this->strPath.'/'.$this->varValue).$this->strExtension, $session['CURRENT']['IDS'])) !== false)
			{
				$session['CURRENT']['IDS'][$index] = $this->urlEncode($this->strPath.'/'.$varValue).$this->strExtension;
				$this->Session->setData($session);
			}
		}

		$this->varValue = $varValue;
	}


	/**
	 * Return the name of the current palette
	 * @return string
	 */
	public function getPalette()
	{
		return $GLOBALS['TL_DCA'][$this->strTable]['palettes']['default'];
	}


	/**
	 * Generate a particular subpart of the tree and return it as HTML string
	 * @param string
	 * @param integer
	 * @return string
	 */
	public function ajaxTreeView($strFolder, $level)
	{
		if (!$this->Input->post('isAjax'))
		{
			return '';
		}

		$blnClipboard = false;
		$arrClipboard = $this->Session->get('CLIPBOARD');

		// Check clipboard
		if (isset($arrClipboard[$this->strTable]) && count($arrClipboard[$this->strTable]))
		{
			$blnClipboard = true;
			$arrClipboard = $arrClipboard[$this->strTable];
		}

		$this->import('Files');
		return $this->generateTree(TL_ROOT.'/'.$strFolder, ($level * 20), false, false, ($blnClipboard ? $arrClipboard : false));
	}


	/**
	 * Render the file tree and return it as HTML string
	 * @param string
	 * @param integer
	 * @param boolean
	 * @param boolean
	 * @param array
	 * @return string
	 */
	protected function generateTree($path, $intMargin, $mount=false, $blnProtected=false, $arrClipboard=false)
	{
		static $session;
		$session = $this->Session->getData();

		// Get session data and toggle nodes
		if ($this->Input->get('tg'))
		{
			$session['filetree'][$this->Input->get('tg')] = (isset($session['filetree'][$this->Input->get('tg')]) && $session['filetree'][$this->Input->get('tg')] == 1) ? 0 : 1;
			$this->Session->setData($session);

			$this->redirect(preg_replace('/(&(amp;)?|\?)tg=[^& ]*/i', '', $this->Environment->request));
		}

		$return = '';
		$files = array();
		$folders = array();
		$intSpacing = 20;
		$level = ($intMargin / $intSpacing + 1);

		// Mount folder
		if ($mount)
		{
			$folders = array($path);
		}

		// Scan directory and sort the result
		else
		{
			foreach (scan($path) as $v)
			{
				if (!is_dir($path . '/' . $v) && $v != '.DS_Store')
				{
					$files[] = $path . '/' . $v;
					continue;
				}

				if ($v == '__new__')
				{
					$this->Files->rmdir(str_replace(TL_ROOT.'/', '', $path) . '/' . $v);
					continue;
				}

				if (substr($v, 0, 1) != '.')
				{
					$folders[] = $path . '/' . $v;
				}
			}

			natcasesort($folders);
			$folders = array_values($folders);

			natcasesort($files);
			$files = array_values($files);
		}

		// Folders
		for ($f=0; $f<count($folders); $f++)
		{
			$return .= "\n  " . '<li class="tl_folder" onmouseover="Theme.hoverDiv(this, 1);" onmouseout="Theme.hoverDiv(this, 0);"><div class="tl_left" style="padding-left:'.$intMargin.'px;">';

			$md5 = md5($folders[$f]);
			$content = scan($folders[$f]);
			$folderAttribute = 'style="margin-left:20px;"';
			$currentFolder = str_replace(TL_ROOT.'/', '', $folders[$f]);
			$session['filetree'][$md5] = is_numeric($session['filetree'][$md5]) ? $session['filetree'][$md5] : 0;
			$currentEncoded = $this->urlEncode($currentFolder);
			$countFiles = count($content);

			// Subtract files that will not be shown
			if (!empty($this->arrValidFileTypes))
			{
				foreach ($content as $file)
				{
					// Folders
					if (is_dir($folders[$f] .'/'. $file))
					{
						if ($file == '.svn')
						{
							--$countFiles;
						}
					}

					// Files
					elseif (!in_array(strtolower(substr($file, (strrpos($file, '.') + 1))), $this->arrValidFileTypes))
					{
						--$countFiles;
					}
				}
			}

			// Add a toggle button if there are childs
			if ($countFiles > 0)
			{
				$folderAttribute = '';
				$img = ($session['filetree'][$md5] == 1) ? 'folMinus.gif' : 'folPlus.gif';
				$alt = ($session['filetree'][$md5] == 1) ? $GLOBALS['TL_LANG']['MSC']['collapseNode'] : $GLOBALS['TL_LANG']['MSC']['expandNode'];
				$return .= '<a href="'.$this->addToUrl('tg='.$md5).'" title="'.specialchars($alt).'" onclick="Backend.getScrollOffset(); return AjaxRequest.toggleFileManager(this, \'filetree_'.$md5.'\', \''.$currentFolder.'\', '.$level.');">'.$this->generateImage($img, '', 'style="margin-right:2px;"').'</a>';
			}

			$protected = ($blnProtected === true || array_search('.htaccess', $content) !== false) ? true : false;
			$folderImg = ($session['filetree'][$md5] == 1 && $countFiles > 0) ? ($protected ? 'folderOP.gif' : 'folderO.gif') : ($protected ? 'folderCP.gif' : 'folderC.gif');

			// Add the current folder
			$return .= $this->generateImage($folderImg, '', $folderAttribute).' <a href="' . $this->addToUrl('node='.$currentEncoded) . '"><strong>'.specialchars(basename($currentFolder)).'</strong></a></div> <div class="tl_right">';

			// Paste buttons
			if ($arrClipboard !== false && $this->Input->get('act') != 'select')
			{
				$imagePasteInto = $this->generateImage('pasteinto.gif', $GLOBALS['TL_LANG'][$this->strTable]['pasteinto'][0], 'class="blink"');
				$return .= (($arrClipboard['mode'] == 'cut' || $arrClipboard['mode'] == 'copy') && preg_match('/^' . preg_quote($arrClipboard['id'], '/') . '/i', $currentFolder)) ? $this->generateImage('pasteinto_.gif', '', 'class="blink"') : '<a href="'.$this->addToUrl('act='.$arrClipboard['mode'].'&amp;mode=2&amp;pid='.$currentEncoded.(!is_array($arrClipboard['id']) ? '&amp;id='.$arrClipboard['id'] : '')).'" title="'.specialchars($GLOBALS['TL_LANG'][$this->strTable]['pasteinto'][1]).'" onclick="Backend.getScrollOffset();">'.$imagePasteInto.'</a> ';
			}

			// Default buttons (do not display buttons for mounted folders)
			elseif (!$mount)
			{
				$return .= ($this->Input->get('act') == 'select') ? '<input type="checkbox" name="IDS[]" id="ids_'.md5($currentEncoded).'" class="tl_tree_checkbox" value="'.$currentEncoded.'" />' : $this->generateButtons(array('id'=>$currentEncoded), $this->strTable);
			}

			$return .= '</div><div style="clear:both;"></div></li>';

			// Call next node
			if (count($content) > 0 && $session['filetree'][$md5] == 1)
			{
				$return .= '<li class="parent" id="filetree_'.$md5.'"><ul class="level_'.$level.'">';
				$return .= $this->generateTree($folders[$f], ($intMargin + $intSpacing), false, $protected, $arrClipboard);
				$return .= '</ul></li>';
			}
		}

		// Process files
		for ($h=0; $h<count($files); $h++)
		{
			$thumbnail = '';
			$popupWidth = 600;
			$popupHeight = 235;
			$currentFile = str_replace(TL_ROOT.'/', '', $files[$h]);

			$objFile = new File($currentFile);

			if (!empty($this->arrValidFileTypes) && !in_array($objFile->extension, $this->arrValidFileTypes))
			{
				continue;
			}

			$currentEncoded = $this->urlEncode($currentFile);
			$return .= "\n  " . '<li class="tl_file" onmouseover="Theme.hoverDiv(this, 1);" onmouseout="Theme.hoverDiv(this, 0);"><div class="tl_left" style="padding-left:'.($intMargin + $intSpacing).'px;">';

			// Generate the thumbnail
			if ($objFile->isGdImage && $objFile->height > 0)
			{
				$popupWidth = ($objFile->width > 600) ? ($objFile->width + 61) : 661;
				$popupHeight = ($objFile->height + 286);
				$thumbnail .= ' <span class="tl_gray">('.$this->getReadableSize($objFile->filesize).', '.$objFile->width.'x'.$objFile->height.' px)</span>';

				if ($GLOBALS['TL_CONFIG']['thumbnails'] && $objFile->height <= 3000 && $objFile->width <= 3000)
				{
					$_height = ($objFile->height < 70) ? $objFile->height : 70;
					$_width = (($objFile->width * $_height / $objFile->height) > 400) ? 90 : '';

					$thumbnail .= '<br /><a href="contao/popup.php?src='.$currentEncoded.'" title="'.specialchars($GLOBALS['TL_LANG']['MSC']['view']).'" onclick="Backend.openWindow(this, '.$popupWidth.', '.$popupHeight.'); return false;" ><img src="' . $this->getImage($currentEncoded, $_width, $_height) . '" alt="" style="margin:0px 0px 2px 23px;" /></a>';
				}
			}
			else
			{
				$thumbnail .= ' <span class="tl_gray">('.$this->getReadableSize($objFile->filesize).')</span>';
			}

			$_buttons = '&nbsp;';

			// No popup links for templates
			if ($this->strTable == 'tl_templates')
			{
				$return .= $this->generateImage($objFile->icon).' '.utf8_convert_encoding(specialchars(basename($currentFile)), $GLOBALS['TL_CONFIG']['characterSet']).'</div> <div class="tl_right">';
			}
			else
			{
				$return .= '<a href="contao/popup.php?src='.$currentEncoded.'" title="'.specialchars($GLOBALS['TL_LANG']['MSC']['view']).'" onclick="Backend.openWindow(this, '.$popupWidth.', '.$popupHeight.'); return false;" >' . $this->generateImage($objFile->icon).' '.utf8_convert_encoding(specialchars(basename($currentFile)), $GLOBALS['TL_CONFIG']['characterSet']).'</a>'.$thumbnail.'</div> <div class="tl_right">';
			}

			// Buttons
			if ($arrClipboard !== false && $this->Input->get('act') != 'select')
			{
				$_buttons = '&nbsp;';
			}
			else
			{
				$_buttons = ($this->Input->get('act') == 'select') ? '<input type="checkbox" name="IDS[]" id="ids_'.md5($currentEncoded).'" class="tl_tree_checkbox" value="'.$currentEncoded.'" />' : $this->generateButtons(array('id'=>$currentEncoded), $this->strTable);
			}

			$return .= $_buttons . '</div><div style="clear:both;"></div></li>';
		}

		return $return;
	}


	/**
	 * Return true if the current folder is mounted
	 * @param string
	 * @return boolean
	 */
	protected function isMounted($strFolder)
	{
		if ($strFolder == '')
		{
			return false;
		}

		if (!count($this->arrFilemounts))
		{
			return true;
		}

		$path = $strFolder;

		while (is_array($this->arrFilemounts) && substr_count($path, '/') > 0)
		{
			if (in_array($path, $this->arrFilemounts))
			{
				return true;
			}

			$path = dirname($path);
		}

		return false;
	}


	/**
	 * Check a file operation
	 * @param string
	 * @return boolean
	 */
	protected function isValid($strFile)
	{
		$strFolder = $this->Input->get('pid', true);

		// Check the path
		if (strpos($strFile, '../') !== false)
		{
			$this->log('Invalid file name "'.$strFile.'" (hacking attempt)', 'DC_Folder isValid()', TL_ERROR);
			$this->redirect('contao/main.php?act=error');
		}
		elseif (strpos($strFolder, '../') !== false)
		{
			$this->log('Invalid folder name "'.$strFolder.'" (hacking attempt)', 'DC_Folder isValid()', TL_ERROR);
			$this->redirect('contao/main.php?act=error');
		}

		// Check for valid file types
		if (!empty($this->arrValidFileTypes) && is_file(TL_ROOT . '/' . $strFile))
		{
			$fileinfo = preg_replace('/.*\.(.*)$/ui', '$1', $strFile);

			if (!in_array(strtolower($fileinfo), $this->arrValidFileTypes))
			{
				$this->log('File "'.$strFile.'" is not an allowed file type', 'DC_Folder isValid()', TL_ERROR);
				$this->redirect('contao/main.php?act=error');
			}
		}

		// Check whether the file is within the files directory
		if (!preg_match('/^'.preg_quote($GLOBALS['TL_CONFIG']['uploadPath'], '/').'/i', $strFile))
		{
			$this->log('File or folder "'.$strFile.'" is not within the files directory', 'DC_Folder isValid()', TL_ERROR);
			$this->redirect('contao/main.php?act=error');
		}

		// Check whether the parent folder is within the files directory
		if ($strFolder && !preg_match('/^'.preg_quote($GLOBALS['TL_CONFIG']['uploadPath'], '/').'/i', $strFolder))
		{
			$this->log('Parent folder "'.$strFolder.'" is not within the files directory', 'DC_Folder isValid()', TL_ERROR);
			$this->redirect('contao/main.php?act=error');
		}

		// Do not allow file operations on root folders
		if ($this->Input->get('act') == 'edit' || $this->Input->get('act') == 'paste' || $this->Input->get('act') == 'delete')
		{
			if (in_array($strFile, $this->arrFilemounts))
			{
				$this->log('Attempt to edit, copy, move or delete root folder "'.$strFile.'"', 'DC_Folder isValid()', TL_ERROR);
				$this->redirect('contao/main.php?act=error');
			}
		}

		return true;
	}


	/**
	 * Return an array of encrypted folder names
	 * @param string
	 * @return array
	 */
	protected function getMD5Folders($strPath)
	{
		$arrFiles = array();

		foreach (scan(TL_ROOT . '/' . $strPath) as $strFile)
		{
			if (!is_dir(TL_ROOT . '/' . $strPath . '/' . $strFile))
			{
				continue;
			}

			$arrFiles[md5(TL_ROOT . '/' . $strPath . '/' . $strFile)] = 1;
			$arrFiles = array_merge($arrFiles, $this->getMD5Folders($strPath . '/' . $strFile));
		}

		return $arrFiles;
	}
}

?>
