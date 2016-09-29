<?hh

abstract class BaseSocket {
	protected resource $socket;

	abstract protected static function createSocket
		(string $address, ?int &$errno, ?string &$errstr): resource;

	public static function create(string $host, int $port): this {
		$socket = static::createSocket("tcp://$host:$port", $errNo, $errStr);
		if (!$socket) {
			throw new Exception($errStr, $errNo);
		}
		return new static($socket);
	}

	public function __construct(resource $socket) {
		$this->socket = $socket;
		stream_set_blocking($this->socket, 0);
	}

	public function close() {
		fclose($this->socket);
	}

	public function __toString(): string {
		return (string)$this->socket;
	}
}

class ClientSocket extends BaseSocket {

	protected static function createSocket
		(string $address, ?int &$errno, ?string &$errstr): resource
	{
		return stream_socket_client(
			$address,
			$errno,
			$errstr,
			ini_get('default_socket_timeout'),
			STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
		);
	}

	public function readAsync(int $size): Generator {
		yield waitForRead($this->socket);
		return fread($this->socket, $size);
	}

	public function writeAsync($string): Generator {
		yield waitForWrite($this->socket);
		return fwrite($this->socket, $string);
	}
}

class ServerSocket extends BaseSocket {

	protected static function createSocket
		(string $address, ?int &$errno, ?string &$errstr): resource
	{
		return stream_socket_server($address, $errNo, $errStr);
	}

	public function acceptClientAsync(): Generator {
		yield waitForRead($this->socket);
		return new ClientSocket(
			stream_socket_accept($this->socket, 0)
		);
	}
}

class Task {
	protected int $id;
	protected Generator $coroutine;
	protected mixed $sendValue = null;
	protected bool $beforeFirstYield = true;
	protected (function(Task): void) $onResult;

	public function __construct(int $id, Generator $coroutine) {
		$this->id = $id;
		$this->coroutine = $coroutine;
	}

	public function getId(): int {
		return $this->id;
	}

	public function setSendValue(mixed $sendValue) {
		$this->sendValue = $sendValue;
	}

	public function run(): mixed {
		if ($this->beforeFirstYield) {
			$this->beforeFirstYield = false;
		} else {
			$this->coroutine->send($this->sendValue);
			$this->sendValue = null;
		}

		if ($this->isFinished() and $this->onResult) {
			$onResult = $this->onResult;
			$onResult($this);
		}

		return $this->coroutine->current();
	}

	public function isFinished(): bool {
		return !$this->coroutine->valid();
	}

	public function getResult(): mixed {
		return $this->coroutine->getReturn();
	}

	public function onResult((function(Task): void) $onResult) {
		$this->onResult = $onResult;
		if ($this->isFinished()) {
			$onResult($this);
		}
	}
}

enum Wait: int {
	READ = 1;
	WRITE = 2;
}

class Scheduler {
	protected int $maxTaskId = 0;
	protected SplQueue<Task> $taskQueue;

	public function __construct() {
		$this->taskQueue = new SplQueue();
	}

	public function newTask(Generator $coroutine): Task {
		$tid = ++$this->maxTaskId;
		echo "Create new task $tid \n";
		$task = new Task($tid, $coroutine);
		$this->schedule($task);
		return $task;
	}

	public function schedule(Task $task) {
		echo "Shedule task {$task->getId()} \n";
		$this->taskQueue->enqueue($task);
	}

	public function run() {
		while (
			!$this->taskQueue->isEmpty()
			or !empty($this->waitingTasks)
		) {
			if (!$this->taskQueue->isEmpty()) {
				$this->runTask($this->taskQueue->dequeue());
			}
			if (!empty($this->waitingTasks)) {
				$this->ioPoll(
					$this->taskQueue->isEmpty() ? null : 0
				);
			}
		}
	}

	private function runTask(Task $task) {
		echo "Run task {$task->getId()} \n";

		$retval = $task->run();

		if ($retval instanceof SystemCall) {
			$retval($task, $this);
		} elseif (!$task->isFinished()) {
			$this->schedule($task);
		}
	}

	protected array<int, array<Task>> $waitingTasks = [];
	protected array<Wait, array<int, resource>> $waitingSockets = [
		Wait::READ => [],
		Wait::WRITE => [],
	];

	public function waitForRead(resource $socket, Task $task) {
		$this->waitSocket($socket, $task, Wait::READ);
	}

	public function waitForWrite(resource $socket, Task $task) {
		$this->waitSocket($socket, $task, Wait::WRITE);
	}

	protected function waitSocket(resource $socket, Task $task, Wait $type) {
		$key = (int) $socket;
		$this->waitingTasks[$key][] = $task;
		$this->waitingSockets[$type][$key] = $socket;
	}

	protected function ioPoll(?int $timeout) {
		$eSocks = []; // dummy
		$rSocks = $this->waitingSockets[Wait::READ];
		$wSocks = $this->waitingSockets[Wait::WRITE];

		echo "Wait for socket \n";
		var_dump($rSocks, $wSocks);

		if (!stream_select($rSocks, $wSocks, $eSocks, $timeout)) {
			echo "No sockets \n";
			var_dump($rSocks, $wSocks, $eSocks);
			return;
		}

		$allSocks = [
			Wait::READ => $rSocks,
			Wait::WRITE => $wSocks,
		];

		foreach ($allSocks as $type => $socks) {
			foreach ($socks as $socket) {
				$key = (int) $socket;
				$tasks = $this->waitingTasks[$key];

				unset($this->waitingTasks[$key]);
				unset($this->waitingSockets[$type][$key]);

				foreach ($tasks as $task) {
					$this->schedule($task);
				}
			}
		}
	}
}

class SystemCall {
	protected (function(Task, Scheduler): mixed) $callback;

	public function __construct((function(Task, Scheduler): mixed) $callback) {
		$this->callback = $callback;
	}

	public function __invoke(Task $task, Scheduler $scheduler): mixed {
		$callback = $this->callback; // Can't call it directly in PHP :/
		return $callback($task, $scheduler);
	}
}

function newTask(Generator $coroutine): SystemCall {
	return new SystemCall(
		(Task $task, Scheduler $scheduler) ==> {
			$task->setSendValue($scheduler->newTask($coroutine));
			$scheduler->schedule($task);
		}
	);
}

function waitForRead($socket): SystemCall {
	return new SystemCall(
		(Task $task, Scheduler $scheduler) ==> {
			$scheduler->waitForRead($socket, $task);
		}
	);
}

function waitForWrite($socket): SystemCall {
	return new SystemCall(
		(Task $task, Scheduler $scheduler) ==> {
			$scheduler->waitForWrite($socket, $task);
		}
	);
}

function waitTasks(array<Task> $tasks): SystemCall {
	return new SystemCall(
		(Task $currTask, Scheduler $scheduler) ==> {
			$allResults = [];
			$onResult = function(Task $task)
				use(&$allResults, $tasks, $currTask, $scheduler)
			{
				echo "On result of task {$task->getId()} \n";
				$allResults[$task->getId()] = $task->getResult();
				if (count($tasks) == count($allResults)) {
					$currTask->setSendValue($allResults);
					$scheduler->schedule($currTask);
				}
			};
			foreach ($tasks as $task) {
				$task->onResult($onResult);
			}
		}
	);
}

function server(string $host, int $port): Generator {
	echo "Starting server $host at port $port...\n";

	$serverSocket = ServerSocket::create($host, $port);

	while (true) {
		$clientSocket = yield from $serverSocket->acceptClientAsync();
		echo "Accept socket $clientSocket \n";
		yield newTask(handleClient($clientSocket));
	}
}

function handleClient(ClientSocket $socket): Generator {
	$data = yield from $socket->readAsync(8192);

	echo "Read from socket $socket \n$data \n";

	$msg = "Received following request:\n\n$data";
	$msgLength = strlen($msg);

	$response = <<<RES
HTTP/1.1 200 OK
Content-Type: text/plain
Content-Length: $msgLength
Connection: close

$msg
RES;

	yield from $socket->writeAsync($response);

	echo "Write to socket $socket \n";

	$socket->close();
}

function requests(): Generator {
	// 84.234.75.250:80

	$tasks = [];
	foreach (['84.234.75.250', '84.234.75.250', '84.234.75.250'] as $host) {
		$tasks[] = yield newTask(request($host));
	}

	$data = yield waitTasks($tasks);

	var_dump($data);
}

function request(string $host, int $port = 80): Generator {
	$socket = ClientSocket::create($host, $port);

	echo "Start request \n";

	$request = <<<RES
GET / HTTP/1.1
Host: 84.234.75.250
Connection: keep-alive
Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8
Upgrade-Insecure-Requests: 1
User-Agent: Mozilla/5.0 (Windows NT 6.1; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/47.0.2526.106 Safari/537.36
Accept-Encoding: gzip, deflate, sdch
Accept-Language: ru-RU,ru;q=0.8,en-US;q=0.6,en;q=0.4
If-Modified-Since: Tue, 05 Jan 2016 20:10:13 GMT


RES;

	$data = yield from $socket->writeAsync($request);
	var_dump($data);

	$response = yield from $socket->readAsync(64);
	// var_dump($response);

	$socket->close();

	return $response;
}

$scheduler = new Scheduler;
$scheduler->newTask(server('192.168.0.97', 8080));
// $scheduler->newTask(requests());
$scheduler->run();