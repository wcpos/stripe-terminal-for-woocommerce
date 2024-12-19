import React from 'react';
import { Button } from '../components/Button/Button';
import { Group } from '../components/Group/Group';
import { Section } from '../components/Section/Section';
import { Text } from '../components/Text/Text';
import { TextInput } from '../components/TextInput/TextInput';
import { Select } from '../components/Select/Select';
import { Link } from '../components/Link/Link';
import type { Location, Reader } from '@stripe/terminal-js';
import type { Client } from '../client';

interface RegisterNewReaderProps {
	client: Client;
	onReaderRegistered: (reader: Reader) => void;
	onClickCancel: () => void;
}

export const RegisterNewReader = ({
	client,
	onReaderRegistered,
	onClickCancel,
}: RegisterNewReaderProps) => {
	const [readerCode, setReaderCode] = React.useState<string | null>(null);
	const [readerLabel, setReaderLabel] = React.useState<string | null>(null);
	const [readerLocationId, setReaderLocationId] = React.useState<string | null>(null);
	const [locations, setLocations] = React.useState<Location[]>([]);
	const [isSubmitting, setIsSubmitting] = React.useState(false);

	React.useEffect(() => {
		client.listLocations().then((fetchedLocations) => {
			setLocations(fetchedLocations);
			if (fetchedLocations.length >= 1) {
				setReaderLocationId(fetchedLocations[0].id);
			}
		});
	}, [client]);

	const handleRegisterNewReader = async () => {
		if (readerCode && readerLabel && readerLocationId) {
			setIsSubmitting(true);
			try {
				// Register the new reader
				const reader = await client.registerDevice({
					label: readerLabel,
					registrationCode: readerCode,
					location: readerLocationId,
				});
				// Pass the registered reader back to the parent
				onReaderRegistered(reader);
			} catch (error) {
				console.error('Error registering the reader:', error);
			} finally {
				setIsSubmitting(false);
			}
		}
	};

	return (
		<Section>
			<Group>
				<div className="stwc-border-b stwc-border-gray-200 stwc-p-4">
					<Text className="stwc-text-base stwc-mb-2">Register new reader</Text>
					<Text className="stwc-text-xs" color="lightGrey">
						Enter the key sequence 0-7-1-3-9 on the reader to display its unique registration code.
					</Text>
				</div>

				<div className="stwc-p-4">
					{/* Registration Code */}
					<div className="stwc-flex stwc-flex-col stwc-gap-2 stwc-mb-4">
						<Text className="stwc-text-sm" color="darkGrey">
							Registration code
						</Text>
						<TextInput
							placeholder="quick-brown-fox"
							value={readerCode || ''}
							onChange={(str) => setReaderCode(str)}
							ariaLabel="Registration code"
						/>
					</div>

					{/* Reader Label */}
					<div className="stwc-flex stwc-flex-col stwc-gap-2 stwc-mb-4">
						<Text className="stwc-text-sm" color="darkGrey">
							Reader label
						</Text>
						<TextInput
							placeholder="Front desk"
							value={readerLabel || ''}
							onChange={(str) => setReaderLabel(str)}
							ariaLabel="Reader label"
						/>
					</div>

					{/* Reader Location */}
					<div className="stwc-flex stwc-flex-col stwc-gap-2 stwc-mb-4">
						<Text className="stwc-text-sm" color="darkGrey">
							Reader location
						</Text>
						{locations.length === 0 ? (
							<Text className="stwc-text-xs" color="lightGrey">
								Looks like you don't have any locations yet. Start by creating one in{' '}
								<Link href="https://dashboard.stripe.com/terminal/locations">the dashboard</Link>.
							</Text>
						) : (
							<Select
								items={locations.map((location) => ({
									value: location.id,
									label: `${location.display_name} (${location.id})`,
								}))}
								value={readerLocationId || ''}
								onChange={(str) => setReaderLocationId(str)}
								required
							/>
						)}
					</div>
				</div>

				{/* Actions */}
				<div className="stwc-flex stwc-flex-row stwc-gap-4 stwc-justify-center stwc-border-t stwc-border-gray-200 stwc-p-4">
					<Button onClick={onClickCancel}>
						<Text>Cancel</Text>
					</Button>
					<Button
						onClick={handleRegisterNewReader}
						disabled={!readerCode || !readerLabel || isSubmitting}
					>
						<Text>{isSubmitting ? 'Registering...' : 'Register'}</Text>
					</Button>
				</div>
			</Group>
		</Section>
	);
};
