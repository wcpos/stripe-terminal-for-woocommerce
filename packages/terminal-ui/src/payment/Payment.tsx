import { Group } from '../components/Group/Group';
import { Text } from '../components/Text/Text';
import { Button } from '../components/Button/Button';
import { useCollectPayment } from './useCollectPayment';
import type { Client } from '../client';
import type { Terminal } from '@stripe/terminal-js';

interface PaymentProps {
	client: Client;
	terminal: Terminal;
}

export const Payment = ({ client, terminal }: PaymentProps) => {
	const {
		showPaymentOptions,
		paymentProgress,
		cancelablePayment,
		handleCollectCardPayment,
		handleCancelPayment,
	} = useCollectPayment({ client, terminal });

	return (
		<Group>
			<div className="stwc-flex stwc-flex-row stwc-justify-between stwc-items-center stwc-border-b stwc-border-gray-200 stwc-p-4">
				Payment
			</div>

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
