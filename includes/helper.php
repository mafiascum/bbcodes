<?php
/**
 *
 * @package phpBB Extension - Mafiascum BBCodes
 * @copyright (c) 2018 mafiascum.net
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 *
 */

namespace mafiascum\bbcodes\includes;

use phpbb\db\driver\factory as database;

class helper
{

	/** @var \phpbb\db\driver\factory */
	protected $db;

	/** @var string */
	protected $root_path;

	/** @var string */
	protected $php_ext;

	/** @var \acp_bbcodes */
	protected $acp_bbcodes;

	/**
	 * Constructor of the helper class.
	 *
	 * @param \phpbb\db\driver\factory		$db
	 * @param string						$root_path
	 * @param string						$php_ext
	 *
	 * @return void
	 */
	public function __construct(database $db, $root_path, $php_ext)
	{
		$this->db = $db;
		$this->root_path = $root_path;
		$this->php_ext = $php_ext;

		if (!class_exists('acp_bbcodes'))
		{
			include($this->root_path . 'includes/acp/acp_bbcodes.' . $this->php_ext);
		}

		$this->acp_bbcodes = new \acp_bbcodes;
	}

	function install_all_bbcodes()
	{
		$this->install_bbcode($this->countdown_bbcode_data());
		$this->install_bbcode($this->post_bbcode_data());
		$this->install_bbcode($this->dice_bbcode_data());
	}

	/**
	 * Install the new BBCode adding it in the database or updating it if it already exists.
	 *
	 * @return void
	 */
	public function install_bbcode($data)
	{
		if (empty($data))
		{
			return;
		}

		$old_bbcode_id = (int) $this->bbcode_exists($data['bbcode_tag']);
		if($old_bbcode_id > NUM_CORE_BBCODES)
		{
			return;
		}

		// Remove conflicting BBCode
		//$this->remove_bbcode($data['bbcode_tag']);

		if(!array_key_exists('bbcode_id', $data))
		{
			$data['bbcode_id'] = (int) $this->bbcode_id();
		}
		
		$data = array_replace(
			$data,
			$this->acp_bbcodes->build_regexp(
				$data['bbcode_match'],
				$data['bbcode_tpl']
			)
		);

		// Update or add BBCode
		if ($old_bbcode_id > NUM_CORE_BBCODES)
		{
			$this->update_bbcode($old_bbcode_id, $data);
		}
		else
		{
			$this->add_bbcode($data);
		}
	}

	function uninstall_all_bbcodes()
	{
		$this->uninstall_bbcode($this->countdown_bbcode_data());
		$this->uninstall_bbcode($this->post_bbcode_data());
		$this->uninstall_bbcode($this->dice_bbcode_data());
	}

	/**
	 * Uninstall the BBCode from the database.
	 *
	 * @return void
	 */
	public function uninstall_bbcode($data)
	{
		if (empty($data))
		{
			return;
		}

		$this->remove_bbcode($data['bbcode_tag']);
	}

	/**
	 * Check whether BBCode already exists.
	 *
	 * @param string $bbcode_tag
	 *
	 * @return integer
	 */
	public function bbcode_exists($bbcode_tag = '')
	{
		if (empty($bbcode_tag))
		{
			return -1;
		}

		$sql = 'SELECT bbcode_id
				FROM ' . BBCODES_TABLE . '
				WHERE ' . $this->db->sql_build_array('SELECT', ['bbcode_tag' => $bbcode_tag]);
		$result = $this->db->sql_query($sql);
		$bbcode_id = (int) $this->db->sql_fetchfield('bbcode_id');
		$this->db->sql_freeresult($result);

		// Set invalid index if BBCode doesn't exist to avoid
		// getting the first record of the table
		$bbcode_id = $bbcode_id > NUM_CORE_BBCODES ? $bbcode_id : -1;

		return $bbcode_id;
	}

	/**
	 * Calculate the ID for the BBCode that is about to be installed.
	 *
	 * @return integer
	 */
	public function bbcode_id()
	{
		$sql = 'SELECT MAX(bbcode_id) as last_id
			FROM ' . BBCODES_TABLE;
		$result = $this->db->sql_query($sql);
		$bbcode_id = (int) $this->db->sql_fetchfield('last_id');
		$this->db->sql_freeresult($result);
		$bbcode_id += 1;

		if ($bbcode_id <= NUM_CORE_BBCODES)
		{
			$bbcode_id = NUM_CORE_BBCODES + 1;
		}

		return $bbcode_id;
	}


	/**
	 * Add the BBCode in the database.
	 *
	 * @param array $data
	 *
	 * @return void
	 */
	public function add_bbcode($data = [])
	{
		if (empty($data) ||
			(!empty($data['bbcode_id']) && (int) $data['bbcode_id'] > BBCODE_LIMIT))
		{
			return;
		}

		$sql = 'INSERT INTO ' . BBCODES_TABLE . '
			' . $this->db->sql_build_array('INSERT', $data);

		$this->db->sql_query($sql);

	}

	/**
	 * Remove BBCode by tag.
	 *
	 * @param string $bbcode_tag
	 *
	 * @return void
	 */
	public function remove_bbcode($bbcode_tag = '')
	{
		if (empty($bbcode_tag))
		{
			return;
		}

		$bbcode_id = (int) $this->bbcode_exists($bbcode_tag);

		// Remove only if exists
		if ($bbcode_id > NUM_CORE_BBCODES)
		{
			$sql = 'DELETE FROM ' . BBCODES_TABLE . '
				WHERE bbcode_id = ' . $bbcode_id;
			$this->db->sql_query($sql);
		}
	}

	/**
	 * Update BBCode data if it already exists.
	 *
	 * @param integer	$bbcode_id
	 * @param array		$data
	 *
	 * @return void
	 */
	public function update_bbcode($bbcode_id = -1, $data = [])
	{
		$bbcode_id = (int) $bbcode_id;

		if ($bbcode_id <= NUM_CORE_BBCODES || empty($data))
		{
			return;
		}

		unset($data['bbcode_id']);

		$sql = 'UPDATE ' . BBCODES_TABLE . '
			SET ' . $this->db->sql_build_array('UPDATE', $data) . '
			WHERE bbcode_id = ' . $bbcode_id;
		$this->db->sql_query($sql);
	}

	/**
	 * BBCode data used in the migration files.
	 *
	 * @return array
	 */
	public function countdown_bbcode_data()
	{
		return [
			'bbcode_id'		=> 1450,
			'bbcode_tag'	=> 'countdown',
			'bbcode_match'	=> '[countdown]{TEXT}[/countdown]',
			'bbcode_tpl'	=> '<span class="countdown">{TEXT}</span>',
			'bbcode_helpline'	=> '',
			'display_on_posting'	=> 1
		];
	}

	public function post_bbcode_data()
	{
		return [
			'bbcode_id'		=> 1452,
			'bbcode_tag'	=> 'post=',
			'bbcode_match'	=> '[post=#{NUMBER}]{TEXT2}[/post]',
			'bbcode_tpl'	=> '<a class="postlink post_tag" href="{SERVER_PROTOCOL}{SERVER_NAME}{SCRIPT_PATH}viewtopic.php?p={NUMBER}#p{NUMBER}">{TEXT2}</a>',
			'bbcode_helpline'	=> '',
			'display_on_posting'	=> 1
		];
	}

	public function dice_bbcode_data()
	{
		return [
			'bbcode_id'		=> 1451,
			'bbcode_tag'	=> 'dice',
			'bbcode_match'	=> '[dice]{TEXT}[/dice]',
			'bbcode_tpl'	=> '<span class="dice-tag-original">{TEXT}</span>',
			'bbcode_helpline'	=> '',
			'display_on_posting'	=> 1
		];
	}
}
