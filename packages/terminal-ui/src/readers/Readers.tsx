import React from 'react';
import { ReaderList } from './ReaderList';
import { Group } from '../components/Group/Group';
import { Button } from '../components/Button/Button';
import { Text } from '../components/Text/Text';
import { RegisterNewReader } from './RegisterNewReader';
import { Logger } from '../logger';
import type { Reader, Terminal } from '@stripe/terminal-js';
import type { Client } from '../client';

interface ReadersProps {
	terminal: Terminal;
	client: Client;
	setReader: (reader: Reader | null) => void;
}

// const mockDiscoveredReaders: Reader[] = [
// 	{
// 		id: 'tmr_FDOt2wlRZEdpd7',
// 		object: 'terminal.reader',
// 		action: null,
// 		device_sw_version: null,
// 		device_type: 'bbpos_wisepos_e',
// 		ip_address: '192.168.1.2',
// 		label: 'Blue Rabbit',
// 		livemode: false,
// 		location: 'tml_FDOtHwxAAdIJOh',
// 		metadata: {},
// 		serial_number: '259cd19c-b902-4730-96a1-09183be6e7f7',
// 		status: 'online',
// 	},
// 	{
// 		id: 'tmr_FDOt3xlRYEdpd8',
// 		object: 'terminal.reader',
// 		action: {
// 			failure_code: null,
// 			failure_message: null,
// 			status: 'in_progress',
// 			type: 'process_payment_intent',
// 			process_payment_intent: {
// 				payment_intent: 'pi_3J2OqE2eZvKYlo2C1xGkfZ',
// 			},
// 		},
// 		device_sw_version: '1.0.0',
// 		device_type: 'stripe_m2',
// 		ip_address: '192.168.1.3',
// 		label: 'Red Fox',
// 		livemode: true,
// 		location: null,
// 		metadata: { customKey: 'customValue' },
// 		serial_number: 'abcd1234',
// 		status: 'disconnected',
// 	},
// ];

export const Readers = ({ terminal, client, setReader }: ReadersProps) => {
	const [discoveryInProgress, setDiscoveryInProgress] = React.useState(false);
	const [requestInProgress, setRequestInProgress] = React.useState(false);
	const [discoveredReaders, setDiscoveredReaders] = React.useState<Reader[]>([]);
	const [mode, setMode] = React.useState<'list' | 'register'>('list');

	React.useEffect(() => {
		const discoverAndConnect = async () => {
			if (!terminal) {
				Logger.logMessage('Stripe Terminal is not initialized.');
				return;
			}

			const storedReaderId = localStorage.getItem('readerId');
			if (storedReaderId) {
				Logger.logMessage(`Stored reader ID: ${storedReaderId}`);
				setDiscoveryInProgress(true);
				setRequestInProgress(true);
				try {
					const discoverResult = await terminal.discoverReaders();
					if ('error' in discoverResult) {
						Logger.logMessage(`Failed to discover readers: ${discoverResult.error.message}`);
					} else {
						setDiscoveredReaders(discoverResult.discoveredReaders);
						const reader = discoverResult.discoveredReaders.find(r => r.id === storedReaderId);
						if (reader) {
							await handleConnect(reader);
						}
					}
				} finally {
					setDiscoveryInProgress(false);
					setRequestInProgress(false);
				}
			}
		};

		discoverAndConnect();
	}, [terminal]);

	const handleDiscover = async () => {
		if (!terminal) {
			Logger.logMessage('Stripe Terminal is not initialized.');
			return;
		}

		setDiscoveryInProgress(true);
		setRequestInProgress(true);
		try {
			const discoverResult = await terminal.discoverReaders();
			if ('error' in discoverResult) {
				Logger.logMessage(`Failed to discover readers: ${discoverResult.error.message}`);
			} else {
				setDiscoveredReaders(discoverResult.discoveredReaders);
				Logger.logMessage(
					`Discovered readers: ${discoverResult.discoveredReaders
						.map((r: Reader) => r.label)
						.join(', ')}`
				);
			}
		} finally {
			setDiscoveryInProgress(false);
			setRequestInProgress(false);
		}
	};

	const handleCancelDiscover = () => {
		setDiscoveryInProgress(false);
		Logger.logMessage('Discovery cancelled.');
	};

	const handleConnect = async (reader: Reader) => {
		if (!terminal) {
			Logger.logMessage('Stripe Terminal is not initialized.');
			return;
		}

		setRequestInProgress(true);
		try {
			const connectResult = await terminal.connectReader(reader);
			if ('error' in connectResult) {
				Logger.logMessage(`Failed to connect to reader: ${connectResult.error.message}`);
			} else {
				Logger.logMessage(`Connected to reader: ${connectResult.reader.label}`);
				setReader(connectResult.reader);
				localStorage.setItem('readerId', connectResult.reader.id);
			}
		} finally {
			setRequestInProgress(false);
		}
	};

	const handleUseSimulator = async () => {
		if (!terminal) {
			Logger.logMessage('Stripe Terminal is not initialized.');
			return;
		}

		const simulatedResult = await terminal.discoverReaders({ simulated: true });
		if ('discoveredReaders' in simulatedResult && simulatedResult.discoveredReaders.length > 0) {
			await handleConnect(simulatedResult.discoveredReaders[0]);
			Logger.logMessage('Using simulated reader.');
		} else {
			Logger.logMessage('No simulated readers found.');
		}
	};

	const handleReaderRegistered = (reader: Reader) => {
		{
			Logger.logMessage(`Reader registered successfully: ${reader.label}`);
			handleConnect(reader);
			setMode('list');
		}
	};

	if (mode === 'register') {
		return (
			<RegisterNewReader
				client={client}
				onReaderRegistered={handleReaderRegistered}
				onClickCancel={() => setMode('list')}
			/>
		);
	}

	return (
		<Group>
			<div className="stwc-flex stwc-flex-row stwc-justify-between stwc-items-center stwc-border-b stwc-border-gray-200 stwc-p-4">
				<Text className="stwc-text-base">Connect to a reader</Text>
				{discoveryInProgress ? (
					<Button variant="text" onClick={handleCancelDiscover}>
						Cancel
					</Button>
				) : (
					<Button variant="text" onClick={handleDiscover} disabled={requestInProgress}>
						Discover
					</Button>
				)}
			</div>
			<div>
				<ReaderList
					readers={discoveredReaders}
					discoveryInProgress={discoveryInProgress}
					requestInProgress={requestInProgress}
					onConnect={handleConnect}
				/>
			</div>
			<div className="stwc-flex stwc-flex-row stwc-gap-4 stwc-justify-center stwc-border-t stwc-border-gray-200 stwc-p-4">
				<Button onClick={() => setMode('register')} disabled={requestInProgress}>
					<Text>Register reader</Text>
				</Button>
				<Button onClick={handleUseSimulator} disabled={requestInProgress}>
					<Text>Use simulator</Text>
				</Button>
			</div>
		</Group>
	);
};
