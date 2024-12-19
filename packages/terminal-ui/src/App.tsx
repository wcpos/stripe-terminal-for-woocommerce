import React from 'react';
import { Readers } from './readers/Readers';
import { Logs } from './logs/Logs';
import { ConnectionInfo } from './ConnectionInfo';
import { SimulatorPayment } from './payment/SimulatorPayment';
import { Payment } from './payment/Payment';
import { Group } from './components/Group/Group';
import { stwcConfig } from './stwcConfig';
import type { Reader } from '@stripe/terminal-js';

export const App = () => {
	const { client, terminal } = stwcConfig;
	const [reader, setReader] = React.useState<Reader | null>(null);

	const disconnectReader = async () => {
		if (terminal) {
			await terminal.disconnectReader();
		}
		setReader(null);
		return null;
	};

	const renderContent = () => {
		if (!client || !terminal) {
			return (
				<Group>
					<div>Configuration or initialization failed. Check logs for details.</div>
				</Group>
			);
		}

		if (!reader) {
			return <Readers terminal={terminal} client={client} setReader={setReader} />;
		}

		return reader.id === 'SIMULATOR' ? (
			<SimulatorPayment client={client} terminal={terminal} />
		) : (
			<Payment />
		);
	};

	return (
		<div className="stwc-p-4">
			<ConnectionInfo reader={reader} onClickDisconnect={disconnectReader} />
			{renderContent()}
			<Logs />
		</div>
	);
};
