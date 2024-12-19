import { Text } from '../components/Text/Text';
import { Button } from '../components/Button/Button';
import { cn } from '../components/lib/utils';
import type { Stripe } from 'stripe';

// Define the props interface
interface ReaderListProps {
	readers: Stripe.Terminal.Reader[];
	discoveryInProgress: boolean;
	requestInProgress: boolean;
	onConnect: (reader: Stripe.Terminal.Reader) => void;
}

export const ReaderList = ({
	readers,
	discoveryInProgress,
	requestInProgress,
	onConnect,
}: ReaderListProps) => {
	// Show a loading message during discovery
	if (discoveryInProgress) {
		return (
			<div className="stwc-p-4">
				<Text className="stwc-text-sm" color="darkGrey">
					Discovering...
				</Text>
			</div>
		);
	}

	// Render discovered readers
	if (readers.length > 0) {
		return (
			<div>
				{readers.map((reader, index) => {
					const isOffline = reader.status === 'offline';
					return (
						<div
							key={reader.id}
							className={cn(
								'stwc-flex stwc-justify-between stwc-items-center stwc-py-4 stwc-px-6 stwc-border-b stwc-border-gray-200',
								index === readers.length - 1 && 'stwc-border-b-0'
							)}
						>
							<div className="stwc-flex stwc-flex-col stwc-gap-1">
								<Text className="stwc-text-base stwc-font-bold">
									{reader.label || 'Unnamed Reader'}
								</Text>
								<Text className="stwc-text-xs" color="darkGrey">
									Serial: {reader.serial_number}
								</Text>
								<Text className="stwc-text-xs" color="darkGrey">
									Type: {reader.device_type}
								</Text>
							</div>
							<Button
								disabled={isOffline || requestInProgress}
								onClick={() => onConnect(reader)}
								className="stwc-py-2 stwc-px-4"
							>
								<Text>{isOffline ? 'Offline' : 'Connect'}</Text>
							</Button>
						</div>
					);
				})}
			</div>
		);
	}

	// Show a message when no readers are found
	return (
		<div className="stwc-text-center stwc-p-4">
			{/* Placeholder for icon */}
			{/* <ReaderIcon /> */}
			<Text color="darkGrey" className="stwc-text-sm">
				No readers found. Register a new reader, then discover readers on your account. If you don't
				have hardware, try using the reader simulator provided by the SDK.
			</Text>
		</div>
	);
};
