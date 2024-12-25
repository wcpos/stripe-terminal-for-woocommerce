import React from 'react';
import { CSSTransition, TransitionGroup } from 'react-transition-group';
import { Button } from './components/Button/Button';
import { Logger, LogEntry } from './logger';
import { Text } from './components/Text/Text';
import { Link } from './components/Link/Link';

export const Logs = () => {
	const [logs, setLogs] = React.useState<LogEntry[]>([]);
	const logsEndRef = React.useRef<HTMLDivElement>(null);

	React.useEffect(() => {
		Logger.setCollectors([{ collect }]);
	}, []);

	const collect = (log: LogEntry) => {
		setLogs((prevLogs) => [...prevLogs, log]);
	};

	const clearLogs = () => setLogs([]);

	React.useEffect(() => {
		if (logsEndRef.current) {
			logsEndRef.current.scrollIntoView({ behavior: 'smooth' });
		}
	}, [logs]);

	// Helper function to pretty-print JSON responses
	const renderJSON = (resp?: string) => {
		if (resp) {
			try {
				// Pretty-print JSON with 2-space indentation
				return JSON.stringify(JSON.parse(resp), null, 2);
			} catch {
				// Return raw response if parsing fails
				return resp;
			}
		}
		return null;
	};

	// Helper function to pretty-print JSON requests or handle empty arrays
	const renderRequestJSON = (req: string) => {
		try {
			const json = JSON.parse(req);
			return Array.isArray(json) && json.length === 0 ? '()' : JSON.stringify(json, null, 2);
		} catch {
			// Return raw request string if parsing fails
			return req;
		}
	};

	const renderStructuredLog = (log: LogEntry) => {
		const returnType = log.response ? 'RESPONSE' : log.exception ? 'EXCEPTION' : 'VOID';
		return (
			<div className="stwc-p-4 stwc-border-b stwc-border-gray-700">
				<div className="stwc-flex stwc-justify-between stwc-items-center">
					<Link
						href={log.docsUrl || '#'}
						newWindow
						className="stwc-text-sm stwc-text-blue-400 hover:stwc-underline"
					>
						{log.method}
					</Link>
					<Text color="lightGrey" className="stwc-text-xs">
						{new Date(log.start_time_ms).toLocaleString()}
					</Text>
				</div>
				<div className="stwc-mt-2 stwc-text-gray-300">
					<Text color="lightGrey" className="stwc-text-xs">
						REQUEST
					</Text>
					<pre className="stwc-text-xs stwc-text-gray-400 stwc-overflow-auto">
						<code>{renderRequestJSON(log.request)}</code>
					</pre>
				</div>
				{log.response || log.exception ? (
					<div className="stwc-mt-2">
						<Text color="lightGrey" className="stwc-text-xs">
							{returnType}
						</Text>
						<pre className="stwc-text-xs stwc-text-gray-400 stwc-overflow-auto">
							<code>{renderJSON(log.response || log.exception || '')}</code>
						</pre>
					</div>
				) : null}
			</div>
		);
	};

	const renderPlainLog = (log: LogEntry) => {
		return (
			<div className="stwc-p-4 stwc-border-b stwc-border-gray-700">
				<div className="stwc-flex stwc-justify-between stwc-items-center">
					<Text
						className={`stwc-font-semibold stwc-text-xs ${
							log.type === 'error'
								? 'stwc-text-red-400'
								: log.type === 'success'
									? 'stwc-text-green-400'
									: log.type === 'warning'
										? 'stwc-text-yellow-400'
										: 'stwc-text-blue-400'
						}`}
					>
						{log.type?.toUpperCase()}
					</Text>
					<Text color="lightGrey" className="stwc-text-xs">
						{new Date(log.start_time_ms).toLocaleString()}
					</Text>
				</div>
				<Text color="lightGrey" className="stwc-text-sm stwc-mt-1">
					{log.request}
				</Text>
			</div>
		);
	};

	const renderLogs = () => (
		<div className="stwc-flex stwc-flex-col stwc-w-full stwc-space-y-4">
			<TransitionGroup>
				{logs.map((log) => (
					<CSSTransition key={log.id} timeout={300} classNames="fade">
						{log.method ? renderStructuredLog(log) : renderPlainLog(log)}
					</CSSTransition>
				))}
			</TransitionGroup>
			<div ref={logsEndRef} />
		</div>
	);

	return (
		<div className="stwc-bg-[#262A41] stwc-rounded-xl stwc-shadow-lg stwc-h-[500px] stwc-max-h-[500px] stwc-flex stwc-flex-col">
			<div className="stwc-flex stwc-items-center stwc-justify-between stwc-border-b stwc-border-gray-700 stwc-p-4">
				<Text color="grey" className="stwc-text-base">
					Logs
				</Text>
				<Button variant="text" onClick={clearLogs}>
					CLEAR
				</Button>
			</div>
			<div className="stwc-flex-1 stwc-overflow-y-auto">
				{logs.length < 1 ? (
					<div className="stwc-flex stwc-items-center stwc-justify-center stwc-h-full stwc-text-gray-500">
						<Text color="lightGrey" className="stwc-text-xs">
							No logs yet. Start by connecting to a reader.
						</Text>
					</div>
				) : (
					renderLogs()
				)}
			</div>
		</div>
	);
};
