import React from 'react';
import { Button } from './components/Button';
import { ConnectionStatus } from './components/ConnectionStatus';
import { LogDisplay } from './components/LogDisplay';
import { fetchConnectionToken, discoverReaders } from './utils/api';

import type { Reader } from '@stripe/terminal-js';

export const App = () => {
	const [status, setStatus] = React.useState<'connected' | 'connecting' | 'disconnected'>(
		'disconnected'
	);
	const [logs, setLogs] = React.useState<string[]>([]);
	const [readers] = React.useState<Reader[]>([]);
	const [selectedReader, setSelectedReader] = React.useState<Reader | null>(null);
	const [terminal, setTerminal] = React.useState<any | null>(null);
	const [isSimulator, setIsSimulator] = React.useState(false);

	const log = (message: string) => setLogs((prev) => [...prev, message]);

	React.useEffect(() => {
		const initializeTerminal = async () => {
			if (!window.StripeTerminal) {
				log('Stripe Terminal is not available on the window object.');
				return;
			}

			const stripeTerminal = window.StripeTerminal.create({
				onFetchConnectionToken: fetchConnectionToken,
				onUnexpectedReaderDisconnect: () => {
					log('Reader disconnected unexpectedly.');
					setStatus('disconnected');
				},
			});

			setTerminal(stripeTerminal);
			log('Stripe Terminal initialized.');
		};

		initializeTerminal();
	}, []);

	const connectToReader = async (reader: Reader) => {
		if (!terminal) {
			log('Stripe Terminal is not initialized.');
			return;
		}

		log(`Connecting to reader: ${reader.label}...`);
		setStatus('connecting');

		const result = await terminal.connectReader(reader);

		if (result.error) {
			log(`Error connecting to reader: ${result.error.message}`);
			setStatus('disconnected');
		} else {
			log(`Connected to reader: ${result.reader.label}`);
			setSelectedReader(result.reader);
			setStatus('connected');
		}
	};

	const disconnectReader = async () => {
		if (!terminal) {
			log('Stripe Terminal is not initialized.');
			return;
		}

		log('Disconnecting reader...');
		await terminal.disconnectReader();
		setStatus('disconnected');
		setSelectedReader(null);
		log('Reader disconnected.');
	};

	React.useEffect(() => {
		if (status === 'disconnected') {
			discoverReaders(terminal, isSimulator);
		}
	}, [status, terminal, isSimulator]);

	return (
		<div className="p-4">
			<ConnectionStatus status={status} />
			<div className="mt-4">
				{readers.length > 0 && (
					<div className="mb-4">
						<label className="block mb-2 text-sm font-medium text-gray-700">Select a Reader:</label>
						<select
							className="block w-full p-2 border border-gray-300 rounded"
							onChange={(e) =>
								setSelectedReader(readers.find((r) => r.id === e.target.value) || null)
							}
							value={selectedReader?.id || ''}
						>
							<option value="" disabled>
								-- Select a Reader --
							</option>
							{readers.map((reader) => (
								<option key={reader.id} value={reader.id}>
									{reader.label}
								</option>
							))}
						</select>
					</div>
				)}
				{selectedReader && status === 'disconnected' && (
					<Button label="Connect to Reader" onClick={() => connectToReader(selectedReader)} />
				)}
				{status === 'connected' && <Button label="Disconnect" onClick={disconnectReader} />}
				<Button
					label={`Toggle Simulator (${isSimulator ? 'ON' : 'OFF'})`}
					onClick={() => setIsSimulator((prev) => !prev)}
				/>
			</div>
			<LogDisplay logs={logs} />
		</div>
	);
};
