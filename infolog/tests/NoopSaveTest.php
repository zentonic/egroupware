<?php

/**
 * Tests for infolog_bo::write() not touching modification date and modifier without a change
 *
 * Behaviour under test:
 *
 *  1. Saving an entry without any change writes nothing: info_datemodified and info_modifier
 *     stay as they are, so the entry does not jump to the top of the list sorted by last modified.
 *  2. A real change still sets both.
 *  3. A change of a child sets the modification date of its parent, but keeps the parent's
 *     modifier, as the parent itself was not changed by that user.
 *  4. Saving a child without a change does not touch the parent either.
 *
 * Setup strategy: entries are written like the UI does (read() in user-time, then write() of the
 * whole entry) and then "aged" directly in the database: modification date 90 days back and a
 * different modifier, so any touch by write() is visible.
 *
 * @link http://www.egroupware.org
 * @package infolog
 * @license http://opensource.org/licenses/gpl-license.php GPL - GNU General Public License
 */

namespace EGroupware\Infolog;

require_once realpath(__DIR__.'/../../api/tests/AppTest.php');	// Application test base

class NoopSaveTest extends \EGroupware\Api\AppTest
{
	/**
	 * Modifier set by age(), not a real account: info_modifier is only compared, never resolved
	 */
	const OTHER_MODIFIER = 999999;

	protected $bo;

	protected $info_ids = array();

	protected function setUp() : void
	{
		$this->bo = new \infolog_bo();
		$this->mockTracking($this->bo, 'infolog_tracking');
	}

	protected function tearDown() : void
	{
		// children were added after their parent, so delete them first
		foreach(array_reverse(array_unique($this->info_ids)) as $info_id)
		{
			// Twice, so it's really gone and not just kept for history
			$this->bo->delete($info_id);
			$this->bo->delete($info_id);
		}
		$this->info_ids = array();
		$this->bo = null;
	}

	/**
	 * Create an entry, skipping notifications
	 *
	 * @param int $parent_id =0 id of the parent entry
	 * @return int info_id
	 */
	protected function makeInfolog($parent_id = 0)
	{
		$info = array(
			'info_type'      => 'task',
			'info_subject'   => 'NoopSaveTest ' . $this->name(),
			'info_des'       => 'Description',
			'info_id_parent' => $parent_id,
		);
		$this->info_ids[] = $info_id = $this->bo->write($info, true, true, true, true);
		$this->assertNotEmpty($info_id, 'could not create test entry');

		return $info_id;
	}

	/**
	 * Set modification date 90 days back and a different modifier directly in the database
	 *
	 * @param int $info_id
	 * @return int the modification date set (server-time)
	 */
	protected function age($info_id)
	{
		$modified = $this->bo->now - 90 * 86400;
		$this->bo->so->db->update($this->bo->so->table_name, array(
			'info_datemodified' => $modified,
			'info_modifier'     => self::OTHER_MODIFIER,
		), array('info_id' => $info_id), __LINE__, __FILE__, 'infolog');

		return $modified;
	}

	/**
	 * Save an entry like the UI does: read it in user-time and write it back
	 *
	 * @param int $info_id
	 * @param array $changes =[] fields to change
	 * @return int|boolean return value of write()
	 */
	protected function save($info_id, array $changes = array())
	{
		$info = $this->bo->read($info_id);
		$this->assertIsArray($info, "could not read entry #$info_id");

		$info = $changes + $info;

		// Skipping notifications
		return $this->bo->write($info, true, true, true, true);
	}

	/**
	 * Read the stored entry in server-time
	 *
	 * @param int $info_id
	 * @return array
	 */
	protected function stored($info_id)
	{
		return $this->bo->read($info_id, false, 'server', true);
	}

	/**
	 * Part 1: saving without a change keeps modification date and modifier
	 */
	public function testSaveWithoutChangeKeepsModifiedAndModifier()
	{
		$info_id = $this->makeInfolog();
		$modified = $this->age($info_id);

		$this->assertEquals($info_id, $this->save($info_id),
			'write() of an unchanged entry must still return its id');

		$saved = $this->stored($info_id);
		$this->assertEquals($modified, $saved['info_datemodified'],
			'saving without a change must not touch the modification date');
		$this->assertEquals(self::OTHER_MODIFIER, $saved['info_modifier'],
			'saving without a change must not change the modifier');
	}

	/**
	 * Part 1: an entry with a date completed (set by the server, with seconds) is unchanged too
	 */
	public function testSaveWithoutChangeKeepsModifiedOfCompletedEntry()
	{
		$info_id = $this->makeInfolog();
		$this->save($info_id, array('info_status' => 'done'));
		$modified = $this->age($info_id);

		$this->save($info_id);

		$saved = $this->stored($info_id);
		$this->assertNotEmpty($saved['info_datecompleted'], 'status done must set a date completed');
		$this->assertEquals($modified, $saved['info_datemodified'],
			'saving a completed entry without a change must not touch the modification date');
		$this->assertEquals(self::OTHER_MODIFIER, $saved['info_modifier'],
			'saving a completed entry without a change must not change the modifier');
	}

	/**
	 * Part 2: a real change sets modification date and modifier
	 */
	public function testChangeSetsModifiedAndModifier()
	{
		$info_id = $this->makeInfolog();
		$this->age($info_id);

		$this->assertEquals($info_id, $this->save($info_id, array('info_subject' => 'NoopSaveTest changed')));

		$saved = $this->stored($info_id);
		$this->assertEquals($this->bo->now, $saved['info_datemodified'],
			'a change must set the modification date');
		$this->assertEquals($this->bo->user, $saved['info_modifier'],
			'a change must set the modifier to the current user');
	}

	/**
	 * Part 3: a change of a child sets the parent's modification date, but not its modifier
	 */
	public function testChildChangeTouchesParentDateButNotModifier()
	{
		$parent_id = $this->makeInfolog();
		$child_id = $this->makeInfolog($parent_id);
		$this->age($parent_id);
		$this->age($child_id);

		$this->save($child_id, array('info_subject' => 'NoopSaveTest child changed'));

		$child = $this->stored($child_id);
		$this->assertEquals($this->bo->user, $child['info_modifier'],
			'the changed child must get the current user as modifier');

		$parent = $this->stored($parent_id);
		$this->assertEquals($this->bo->now, $parent['info_datemodified'],
			'a change of a child must set the modification date of its parent');
		$this->assertEquals(self::OTHER_MODIFIER, $parent['info_modifier'],
			'a change of a child must not change the modifier of its parent');
	}

	/**
	 * Part 4: saving a child without a change does not touch its parent
	 */
	public function testChildSaveWithoutChangeKeepsParent()
	{
		$parent_id = $this->makeInfolog();
		$child_id = $this->makeInfolog($parent_id);
		$parent_modified = $this->age($parent_id);
		$child_modified = $this->age($child_id);

		$this->save($child_id);

		$child = $this->stored($child_id);
		$this->assertEquals($child_modified, $child['info_datemodified'],
			'saving a child without a change must not touch its modification date');

		$parent = $this->stored($parent_id);
		$this->assertEquals($parent_modified, $parent['info_datemodified'],
			'saving a child without a change must not touch the modification date of its parent');
		$this->assertEquals(self::OTHER_MODIFIER, $parent['info_modifier'],
			'saving a child without a change must not change the modifier of its parent');
	}
}
