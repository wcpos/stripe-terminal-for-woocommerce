import { Group } from '../components/Group/Group';
import { Text } from '../components/Text/Text';
import { Button } from '../components/Button/Button';
import { useCollectPayment } from './useCollectPayment';
import React from 'react';
import type { Client } from '../client';
import type { Reader, Terminal } from '@stripe/terminal-js';

const MOTO_COMPATIBLE_DEVICES = ['stripe_s700', 'stripe_s710', 'bbpos_wisepos_e'];

interface PaymentProps {
	client: Client;
	terminal: Terminal;
	reader?: Reader | null;
	enableMoto?: boolean;
}

export const Payment = ({ client, terminal, reader, enableMoto }: PaymentProps) => {
	const [isMoto, setIsMoto] = React.useState(false);

	const showMotoToggle =
		enableMoto && reader?.device_type && MOTO_COMPATIBLE_DEVICES.includes(reader.device_type);

	React.useEffect(() => {
		if (!showMotoToggle) {
			setIsMoto(false);
		}
	}, [showMotoToggle]);

	const {
		showPaymentOptions,
		paymentProgress,
		cancelablePayment,
		handleCollectCardPayment,
		handleCancelPayment,
	} = useCollectPayment({ client, terminal, moto: isMoto });

	return (
		<Group>
			<div className="stwc-flex stwc-flex-row stwc-justify-between stwc-items-center stwc-border-b stwc-border-gray-200 stwc-p-4">
				Payment
			</div>

			{showMotoToggle && showPaymentOptions && (
				<div className="stwc-flex stwc-flex-row stwc-items-center stwc-gap-2 stwc-px-4 stwc-py-2 stwc-border-b stwc-border-gray-200">
					<label className="stwc-flex stwc-items-center stwc-gap-2 stwc-cursor-pointer">
						<input
							type="checkbox"
							checked={isMoto}
							onChange={(e) => setIsMoto(e.target.checked)}
						/>
						<Text color="darkGrey">Phone Order</Text>
					</label>
				</div>
			)}

			{showPaymentOptions ? (
				<div className="stwc-flex stwc-flex-col stwc-gap-4 stwc-p-4">
					<Text color="darkGrey">
						Click the Collect Card Payment button to start the payment process.
					</Text>
				</div>
			) : (
				<div className="stwc-flex stwc-flex-col stwc-gap-4 stwc-p-4">
					<Text color="darkGrey">{paymentProgress}</Text>
				</div>
			)}

			{/* Actions */}
			<div className="stwc-flex stwc-flex-row stwc-gap-4 stwc-justify-center stwc-border-t stwc-border-gray-200 stwc-p-4">
				<Button onClick={handleCollectCardPayment}>
					<Text>Collect card payment</Text>
				</Button>
				<Button onClick={handleCancelPayment} disabled={!cancelablePayment}>
					<Text>Cancel payment</Text>
				</Button>
			</div>
		</Group>
	);
};
