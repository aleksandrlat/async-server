<?hh

namespace MyExperiment;

enum Wait: int {
	READ = 1;
	WRITE = 2;
}

async function select_stream(resource $stream, Wait $type): Awaitable<bool> {
	$read = [];
	$write = [];
	$except = [$stream];

	if ($type == Wait::READ) {
		$type = 'reading';
		$read[] = $stream;
	} else if ($type == Wait::WRITE) {
		$type = 'writing';
		$write[] = $stream;
	}

	echo "Await $stream for $type \n";

	$res = stream_select($read, $write, $except, 0);

	var_dump($res);

	if (!empty($except)) {
		throw new \Exception('Except is not empty');
	}

	return $res > 0;
}

async function await_stream(resource $stream, Wait $type): Awaitable<bool> {
	$events = ($type == Wait::READ ? STREAM_AWAIT_READ : STREAM_AWAIT_WRITE);

	var_dump(
		STREAM_AWAIT_READ, 
		STREAM_AWAIT_WRITE,
		STREAM_AWAIT_CLOSED,
		STREAM_AWAIT_READY,
		STREAM_AWAIT_TIMEOUT,
		STREAM_AWAIT_ERROR
	);

	echo "Native stream await \n";

	$res = await stream_await($stream, $events);

	var_dump($res);

	return $res == STREAM_AWAIT_READY;

	while (true) {
		$res = await select_stream($stream, $type);

		echo "Await $stream result \n";
		var_dump($res);

		if ($res === false) {
			await \HH\Asio\usleep(2000000); // second
		} else {
			return true;
		}
	}
}

async function server(string $host, int $port): Awaitable<void> {
	$master = stream_socket_server("tcp://$host:$port");
	stream_set_blocking($master, 0);

	echo "Server $master created \n";

	while (true) {
		// await stream_await($master, STREAM_AWAIT_READ, 1.0);
		$res = await await_stream($master, Wait::READ);

		echo "Await client result \n";
		var_dump($res);

		$clientSocket = stream_socket_accept($master);
		stream_set_blocking($clientSocket, 0);

		handleClient($clientSocket);
	}
}

async function handleClient($socket): Awaitable<void> {
	// await stream_await($socket, STREAM_AWAIT_READ, 1.0);
	await await_stream($socket, Wait::READ);

	$data = fread($socket, 1024);
	echo $data;

	// await stream_await($socket, STREAM_AWAIT_WRITE, 1.0);
	await await_stream($socket, Wait::WRITE);

	fwrite($socket, 'aaaaaaaa');

	fclose($socket);
}

function run(): void {
	\HH\Asio\join(server('192.168.0.97', 8080));
}

run();