<?php
/**
 *
 * @package phpBB Extension - Mafiascum BBCodes
 * @copyright (c) 2018 mafiascum.net
 * @license http://opensource.org/licenses/gpl-2.0.php GNU General Public License v2
 *
 */

namespace mafiascum\bbcodes\migrations;

use mafiascum\bbcodes\includes\helper as bbcodes_helper;

class bbcodes extends \phpbb\db\migration\migration
{

	public function update_schema() {
	}

	/**
	 * Install BBCode in database.
	 *
	 * @return array
	 */
	public function update_data()
	{
		return [
			[
				'custom',
				[
					[
						new bbcodes_helper(
							$this->db,
							$this->phpbb_root_path,
							$this->php_ext
						),
						'install_all_bbcodes'
					]
				],
			]
		];
	}

	/**
	 * Uninstall BBCode from database.
	 *
	 * @return array
	 */
	public function revert_data()
	{
		return [
			[
				'custom',
				[
					[
						new bbcodes_helper(
							$this->db,
							$this->phpbb_root_path,
							$this->php_ext
						),
						'uninstall_all_bbcodes'
					]
				]
			]
		];
	}
}
