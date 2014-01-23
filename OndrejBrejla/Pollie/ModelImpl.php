<?php

namespace OndrejBrejla\Pollie;

use Nette\Object;
use Nette\Environment;
use DibiConnection;

/**
 * ModelImpl - part of Pollie plugin for Nette Framework for voting.
 *
 * @copyright  Copyright (c) 2009 Ondřej Brejla
 * @license    New BSD License
 * @link       http://github.com/OndrejBrejla/Pollie
 */
class ModelImpl extends Object implements Model
{
	const SESSION_NAMESPACE = '__pollie';
	public $active;
	protected $row;

	/**
	 * Connection to the database.
	 *
	 * @var DibiConnection Connection to the database.
	 */
	private $connection;

	/**
	 * Id of the current poll.
	 *
	 * @var Int Id of the current poll.
	 */
	public $id;
	public $question;

	/**
	 * Constructor of the poll control model layer.
	 *
	 * @param mixed $id Id of the current poll.
	 */
	public function __construct($id)
	{
		$this->connection = new DibiConnection(Environment::getConfig('database'));
		if (is_null($id)) { //get last active
			$this->row = $this->connection->fetch('SELECT id,question,active FROM pollie_questions ORDER BY id DESC LIMIT 1');
		} else { //get by id
			$this->row = $this->connection->fetch('SELECT id,question,active FROM pollie_questions WHERE id = %i LIMIT 1', $id);
		}
		if ($this->row) {
			$this->question = $this->row->question;
			$this->id = $this->row->id;
		}

		$sess = Environment::getSession(self::SESSION_NAMESPACE);
		$sess->poll[$id] = FALSE;
	}

	/**
	 * @see Model::getAllVotesCount()
	 */
	public function getAllVotesCount()
	{
		if ($this->id) {
			return $this->connection->fetchSingle('SELECT SUM(votes) FROM pollie_answers WHERE questionId = %i', $this->id);
		}
	}

	/**
	 * @see Model::getQuestion()
	 */
	public function getQuestion()
	{
		return $this->question; //I saved one SQL query
		return $this->connection->fetchSingle('SELECT question FROM pollie_questions WHERE id = %i', $this->id);
	}

	/**
	 * @see Model::getAnswers()
	 */
	public function getAnswers()
	{
		$answers = array();
		if ($this->id) {
			foreach ($this->connection->fetchAll('SELECT id, answer, questionId, votes FROM pollie_answers WHERE questionId = %i', $this->id) as $row) {
				$answers[] = new Answer($row->answer, $row->id, $row->votes, $row->questionId);
			}
		}

		return $answers;
	}

	/**
	 * Makes vote for specified answer id.
	 *
	 * @param int $id Id of specified answer.
	 * @throws \Nette\Application\BadRequestException
	 */
	public function vote($id)
	{
		if ($this->isVotable()) {
			$this->connection->query('UPDATE pollie_answers SET votes = votes + 1 WHERE id = %i', $id, ' AND questionId = %i', $this->id);

			$this->denyVotingForUser();
		} else {
			throw new BadRequestException('Hlasování v není možné.');
		}
	}

	/**
	 * @see Model::isVotable()
	 */
	public function isVotable()
	{
		$sess = Environment::getSession(self::SESSION_NAMESPACE);
		$row = $this->getRow();
		if (!$this->id) { //empty
			return false;
		}
		if ($row['active'] == 'N') {
			return FALSE;
		}
		if ($sess->poll[$this->id] === TRUE) {
			return FALSE;
		} else {
			if ($this->connection->fetchSingle("SELECT COUNT(*) FROM pollie_votes WHERE ip = '$_SERVER[REMOTE_ADDR]' AND questionId = %i AND date + INTERVAL 1 HOUR > NOW()", $this->id)) { //todo
				return FALSE;
			}
		}

		return TRUE;
	}

	public function getRow()
	{
		return $this->row;
	}

	/**
	 * Disables voting for the user who had currently voted.
	 */
	private function denyVotingForUser()
	{
		$sess = Environment::getSession(self::SESSION_NAMESPACE);
		$sess->poll[$this->id] = TRUE;
		$this->connection->query("INSERT INTO pollie_votes (questionId, ip, date) VALUES ($this->id, '$_SERVER[REMOTE_ADDR]', NOW())");
	}

	public function save()
	{
		if ($this->id) {
			$this->connection->query("UPDATE pollie_questions SET question = %s, active = %s WHERE id = %i", $this->question, $this->active, $this->id);
		} else {
			$this->connection->query("INSERT INTO pollie_questions ", ['question' => $this->question,
				'active' => $this->active]);
			$this->id = $this->connection->getInsertId();
		}
	}


}