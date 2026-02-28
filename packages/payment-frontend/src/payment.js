/**
 * Stripe Terminal for WooCommerce - Payment Frontend
 * Simple jQuery-based frontend for handling Stripe Terminal payments via AJAX
 */

import './payment.css';

class StripeTerminalPayment {
  constructor() {
    this.isInitialized = false;
    this.currentPaymentIntent = null;
    this.connectedReader = null;
    this.pollingInterval = null; // Track polling interval
    this.pollingTimeout = null; // Track polling timeout
    this.isDeclined = false;

    // Get WordPress localized data
    this.config = window.stripeTerminalData || {};
    this.ajaxUrl = this.config.ajaxUrl || window.ajaxurl || '/wp-admin/admin-ajax.php';
    this.nonce = this.config.nonce || '';
    this.strings = this.config.strings || {};
    this.readers = this.config.readers || [];
    
    this.init();
  }

  init() {
    // Bind events
    this.bindEvents();
    
    // Initialize the interface
    this.initializeInterface();
    
    this.isInitialized = true;
    
    console.log('Stripe Terminal Payment initialized');
  }

  bindEvents() {
    // Bind to WooCommerce checkout form
    jQuery(document).on('click', '.stripe-terminal-pay-button', this.handlePayment.bind(this));
    jQuery(document).on('click', '.stripe-terminal-cancel-button', this.handleCancel.bind(this));
    jQuery(document).on('click', '.stripe-terminal-simulate-button', this.handleSimulatePayment.bind(this));
    jQuery(document).on('click', '.stripe-terminal-check-status-button', this.handleCheckStatus.bind(this));
    jQuery(document).on('click', '.stripe-terminal-retry-button', this.handleRetryPayment.bind(this));

    // Reader management events
    jQuery(document).on('click', '.stripe-terminal-connect-button', this.handleConnectReader.bind(this));
    jQuery(document).on('click', '.stripe-terminal-disconnect-button', this.handleDisconnectReader.bind(this));
    
    // Logging events
    jQuery(document).on('click', '.stripe-terminal-toggle-log', this.handleToggleLog.bind(this));
    jQuery(document).on('click', '.stripe-terminal-clear-log', this.handleClearLog.bind(this));
    
  }

  async handlePayment(event) {
    event.preventDefault();
    
    if (!this.isInitialized) {
      this.showError(this.strings.systemNotInitialized || 'Payment system not initialized');
      return;
    }

    // Check if a reader is connected
    if (!this.connectedReader) {
      this.showError(this.strings.selectReader || 'Please select a reader to continue');
      return;
    }

    const button = jQuery(event.target);
    const orderId = button.data('order-id') || this.config.orderId;
    const amount = button.data('amount');
    
    if (!orderId || !amount) {
      this.showError(this.strings.missingData || 'Missing order ID or amount');
      return;
    }
    
    try {
      button.prop('disabled', true).text(this.strings.startingPayment || 'Starting payment...');
      
      // Create and process payment intent via AJAX
      const response = await this.createPaymentIntent(orderId, amount);
      
      if (!response || !response.payment_intent || !response.payment_intent.id) {
        throw new Error('Failed to create payment intent');
      }
      
      this.currentPaymentIntent = response.payment_intent;
      button.text(this.strings.paymentInProgress || 'Payment in progress...');
      
      // Update button visibility
      this.updateButtonVisibility();
      
      // Show message to user to use the terminal
      this.showMessage(this.strings.useTerminal || 'Please use the terminal to complete the payment');
      
      // Start polling for payment status
      this.pollPaymentStatus(this.currentPaymentIntent.id, orderId, button);
      
    } catch (error) {
      console.error('Payment error:', error);
      this.showError((this.strings.paymentFailed || 'Payment failed') + ': ' + error.message);
      button.prop('disabled', false).text(this.strings.payWithTerminal || 'Pay with Terminal');
    }
  }

  async createPaymentIntent(orderId, amount) {
    return new Promise((resolve, reject) => {
      const ajaxData = {
        action: 'stripe_terminal_create_payment_intent',
        order_id: orderId,
        amount: amount,
        reader_id: this.connectedReader.id
      };

      // Add order key for guest users
      if (this.config.orderKey) {
        ajaxData.order_key = this.config.orderKey;
      }

      jQuery.ajax({
        url: this.ajaxUrl,
        type: 'POST',
        data: ajaxData,
        success: (response) => {
          if (response.success) {
            resolve(response.data);
          } else {
            reject(new Error(response.data || 'Failed to create payment intent'));
          }
        },
        error: (xhr, status, error) => {
          let errorMessage = 'AJAX error: ' + error;
          try {
            const response = JSON.parse(xhr.responseText);
            if (response.data && response.data.message) {
              errorMessage = response.data.message;
            } else if (response.data) {
              errorMessage = response.data;
            }
          } catch (e) {
            // If we can't parse the response, use the generic error
          }
          reject(new Error(errorMessage));
        }
      });
    });
  }

  async confirmPayment(paymentIntentId, orderId) {
    return new Promise((resolve, reject) => {
      const ajaxData = {
        action: 'stripe_terminal_confirm_payment',
        payment_intent_id: paymentIntentId,
        order_id: orderId
      };

      // Add order key for guest users
      if (this.config.orderKey) {
        ajaxData.order_key = this.config.orderKey;
      }

      jQuery.ajax({
        url: this.ajaxUrl,
        type: 'POST',
        data: ajaxData,
        success: (response) => {
          if (response.success) {
            resolve(response.data);
          } else {
            reject(new Error(response.data || 'Failed to confirm payment'));
          }
        },
        error: (xhr, status, error) => {
          let errorMessage = 'AJAX error: ' + error;
          try {
            const response = JSON.parse(xhr.responseText);
            if (response.data && response.data.message) {
              errorMessage = response.data.message;
            } else if (response.data) {
              errorMessage = response.data;
            }
          } catch (e) {
            // If we can't parse the response, use the generic error
          }
          reject(new Error(errorMessage));
        }
      });
    });
  }

  async cancelPayment(paymentIntentId, orderId) {
    return new Promise((resolve, reject) => {
      const ajaxData = {
        action: 'stripe_terminal_cancel_payment',
        payment_intent_id: paymentIntentId,
        order_id: orderId
      };

      // Add order key for guest users
      if (this.config.orderKey) {
        ajaxData.order_key = this.config.orderKey;
      }

      jQuery.ajax({
        url: this.ajaxUrl,
        type: 'POST',
        data: ajaxData,
        success: (response) => {
          if (response.success) {
            resolve(response.data);
          } else {
            reject(new Error(response.data || 'Failed to cancel payment'));
          }
        },
        error: (xhr, status, error) => {
          let errorMessage = 'AJAX error: ' + error;
          try {
            const response = JSON.parse(xhr.responseText);
            if (response.data && response.data.message) {
              errorMessage = response.data.message;
            } else if (response.data) {
              errorMessage = response.data;
            }
          } catch (e) {
            // If we can't parse the response, use the generic error
          }
          reject(new Error(errorMessage));
        }
      });
    });
  }

  pollPaymentStatus(paymentIntentId, orderId, button) {
    this.stopPolling();

    this.pollingInterval = setInterval(async () => {
      try {
        const response = await jQuery.ajax({
          url: this.ajaxUrl,
          type: 'POST',
          data: {
            action: 'stripe_terminal_check_payment_status',
            order_id: orderId,
            order_key: this.config.orderKey
          }
        });

        if (response.success) {
          const data = response.data;

          if (data.is_paid) {
            this.stopPolling();
            this.handleSuccessfulPayment(data);
          } else if (data.payment_intent_status === 'requires_payment_method' && data.last_payment_error) {
            this.stopPolling();
            this.handleDecline(data, button);
          } else if (data.status === 'failed' || data.status === 'cancelled') {
            this.stopPolling();
            this.showError(this.strings.paymentFailed || 'Payment failed');
            button.prop('disabled', false).text(this.strings.payWithTerminal || 'Pay with Terminal');
            this.currentPaymentIntent = null;
            this.isDeclined = false;
            this.updateButtonVisibility();
          }
        }
      } catch (error) {
        console.error('Payment polling error:', error);
      }
    }, 2000);

    this.pollingTimeout = setTimeout(() => {
      this.stopPolling();
      if (button.prop('disabled')) {
        this.showError(this.strings.paymentTimeout || 'Payment timed out');
        button.prop('disabled', false).text(this.strings.payWithTerminal || 'Pay with Terminal');
        this.currentPaymentIntent = null;
        this.isDeclined = false;
        this.updateButtonVisibility();
      }
    }, 300000);
  }

  handleDecline(data, button) {
    const errorMessage = data.last_payment_error.message || this.strings.cardDeclined || 'Card declined';
    this.showError(errorMessage);
    this.addToLog('Card declined: ' + errorMessage, 'warning');

    this.isDeclined = true;

    button.text(this.strings.payWithTerminal || 'Pay with Terminal');
    button.prop('disabled', true);

    this.updateButtonVisibility();
  }

  handleRetryPayment(event) {
    event.preventDefault();

    if (!this.currentPaymentIntent || !this.currentPaymentIntent.id) {
      this.showError('No active payment to retry');
      return;
    }

    if (!this.connectedReader) {
      this.showError(this.strings.selectReader || 'Please select a reader to continue');
      return;
    }

    const button = jQuery(event.target);
    const orderId = button.data('order-id') || this.config.orderId;
    const payButton = jQuery('.stripe-terminal-pay-button');

    button.prop('disabled', true).text('Retrying...');
    this.isDeclined = false;

    jQuery.ajax({
      url: this.ajaxUrl,
      type: 'POST',
      data: {
        action: 'stripe_terminal_retry_payment',
        order_id: orderId,
        reader_id: this.connectedReader.id,
        order_key: this.config.orderKey
      }
    })
    .done((response) => {
      if (response.success) {
        this.addToLog('Payment retry sent to reader. Present a new card.', 'info');
        this.showMessage(this.strings.useTerminal || 'Please use the terminal to complete the payment');

        payButton.text(this.strings.paymentInProgress || 'Payment in progress...');
        this.updateButtonVisibility();

        this.pollPaymentStatus(this.currentPaymentIntent.id, orderId, payButton);
      } else {
        this.showError('Retry failed: ' + (response.data || 'Unknown error'));
        this.isDeclined = true;
        this.updateButtonVisibility();
      }
      button.prop('disabled', false).text(this.strings.tryAnotherCard || 'Try Another Card');
    })
    .fail((xhr, status, error) => {
      this.showError('Retry failed: ' + error);
      button.prop('disabled', false).text(this.strings.tryAnotherCard || 'Try Another Card');
      this.isDeclined = true;
      this.updateButtonVisibility();
    });
  }

  stopPolling() {
    if (this.pollingInterval) {
      clearInterval(this.pollingInterval);
      this.pollingInterval = null;
    }
    if (this.pollingTimeout) {
      clearTimeout(this.pollingTimeout);
      this.pollingTimeout = null;
    }
  }

  handleCancel(event) {
    event.preventDefault();
    
    // Stop polling immediately when cancel is clicked
    this.stopPolling();
    
    if (this.currentPaymentIntent && this.currentPaymentIntent.id) {
      const orderId = this.config.orderId;
      
      this.cancelPayment(this.currentPaymentIntent.id, orderId)
        .then(() => {
          this.showMessage(this.strings.paymentCancelled || 'Payment cancelled');
          this.currentPaymentIntent = null;
          this.isDeclined = false;
          // Reset button state
          jQuery('.stripe-terminal-pay-button').prop('disabled', false).text(this.strings.payWithTerminal || 'Pay with Terminal');
          // Update button visibility
          this.updateButtonVisibility();
        })
        .catch(error => {
          console.error('Error canceling payment:', error);
          this.showError('Error cancelling payment: ' + error.message);
        });
    } else {
      this.showMessage(this.strings.noActivePayment || 'No active payment to cancel');
    }
  }

  handleSimulatePayment(event) {
    event.preventDefault();
    
    const button = jQuery(event.target);
    const orderId = button.data('order-id') || this.config.orderId;
    
    if (!orderId) {
      this.showError('Missing order ID for simulation');
      return;
    }
    
    if (!this.connectedReader) {
      this.showError('Please connect to a reader first');
      return;
    }
    
    button.prop('disabled', true).text('Simulating...');
    
    // Call the simulate payment AJAX endpoint
    jQuery.ajax({
      url: this.ajaxUrl,
      type: 'POST',
      data: {
        action: 'stripe_terminal_simulate_payment',
        reader_id: this.connectedReader.id,
        order_id: orderId,
        order_key: this.config.orderKey
      }
    })
    .done((response) => {
      if (response.success) {
        const data = response.data;
        this.showSuccess('Payment simulation triggered! Payment Intent: ' + data.payment_intent + '. Check the terminal reader.');
        button.prop('disabled', false).text('Simulate Payment');
        
        // Store the payment intent for potential cancellation
        this.currentPaymentIntent = { id: data.payment_intent };
        this.updateButtonVisibility();
      } else {
        this.showError('Simulation failed: ' + (response.data || 'Unknown error'));
        button.prop('disabled', false).text('Simulate Payment');
      }
    })
    .fail((xhr, status, error) => {
      console.error('Simulate payment error:', error);
      this.showError('Simulation failed: ' + error);
      button.prop('disabled', false).text('Simulate Payment');
    });
  }

  handleCheckStatus(event) {
    event.preventDefault();
    
    const button = jQuery(event.target);
    const orderId = button.data('order-id') || this.config.orderId;
    
    if (!orderId) {
      this.showError('Missing order ID');
      return;
    }
    
    button.prop('disabled', true).text('Checking...');
    this.addToLog('Checking payment status with Stripe...', 'info');
    
    // Call the manual check status AJAX endpoint
    jQuery.ajax({
      url: this.ajaxUrl,
      type: 'POST',
      data: {
        action: 'stripe_terminal_check_stripe_status',
        order_id: orderId,
        order_key: this.config.orderKey
      }
    })
    .done((response) => {
      if (response.success) {
        const data = response.data;
        this.addToLog('Payment status check completed', 'info');
        
        if (data.payment_found && data.payment_successful) {
          this.addToLog('Successful payment found! Processing order...', 'success');
          this.showSuccess('Payment found! Processing order...');
          
          // Trigger order processing
          this.handleSuccessfulPayment(data);
        } else if (data.payment_found && !data.payment_successful) {
          this.addToLog('Payment found but not successful: ' + (data.payment_status || 'unknown'), 'warning');
          this.showMessage('Payment found but not successful: ' + (data.payment_status || 'unknown'));
        } else {
          this.addToLog('No payment found for this order', 'info');
          this.showMessage('No payment found for this order');
        }
      } else {
        this.addToLog('Status check failed: ' + (response.data || 'Unknown error'), 'error');
        this.showError('Status check failed: ' + (response.data || 'Unknown error'));
      }
    })
    .fail((xhr, status, error) => {
      console.error('Check status error:', error);
      this.addToLog('Status check error: ' + error, 'error');
      this.showError('Status check error: ' + error);
    })
    .always(() => {
      button.prop('disabled', false).text('Check Payment Status');
    });
  }

  handleSuccessfulPayment(pollData) {
    // Try to click the place order button first (WooCommerce order-pay page)
    const placeOrderBtn = jQuery('#place_order');
    if (placeOrderBtn.length > 0) {
      this.showSuccess('Payment successful! Processing order...');
      placeOrderBtn.click();
      return;
    }
    
    // Try to submit the order review form (WooCommerce order-pay page)
    const orderReviewForm = jQuery('#order_review');
    if (orderReviewForm.length > 0) {
      this.showSuccess('Payment successful! Processing order...');
      orderReviewForm.submit();
      return;
    }
    
    // Fallback: try standard checkout form
    const checkoutForm = jQuery('form.checkout, form[name="checkout"]');
    if (checkoutForm.length > 0) {
      this.showSuccess('Payment successful! Processing order...');
      checkoutForm.submit();
      return;
    }
    
    // Final fallback: try to find any form on the page
    const anyForm = jQuery('form').first();
    if (anyForm.length > 0) {
      this.showSuccess('Payment successful! Processing order...');
      anyForm.submit();
    } else {
      // No form found, show success message and redirect if we have a return URL
      this.showSuccess('Payment successful! Please refresh the page to continue.');
      
      // If we have a return URL, redirect to the thank you page
      if (pollData.return_url) {
        setTimeout(() => {
          window.location.href = pollData.return_url;
        }, 2000); // Wait 2 seconds to show the success message
      }
    }
  }


  handlePaymentSuccess(result) {
    // Stop polling when payment succeeds
    this.stopPolling();
    
    // Clear current payment intent
    this.currentPaymentIntent = null;
    this.updateButtonVisibility();
    
    // Trigger WordPress event for other scripts to listen to
    jQuery(document).trigger('stripe_terminal_payment_success', [result]);
    
    // If we're on a checkout page, redirect to thank you page
    if (typeof wc_checkout_params !== 'undefined') {
      // WooCommerce checkout - let WooCommerce handle the redirect
      jQuery(document.body).trigger('checkout_error', [{
        message: 'Payment successful, redirecting...'
      }]);
    }
  }

  showError(message) {
    this.showStatusMessage(message, 'error');
  }

  showSuccess(message) {
    this.showStatusMessage(message, 'success');
  }

  showMessage(message) {
    this.showStatusMessage(message, 'info');
  }

  showStatusMessage(message, type = 'info') {
    // Add to log
    this.addToLog(message, type);
    
    // Only show error messages as they're critical
    if (type === 'error') {
      // Hide all existing status messages
      jQuery('.stripe-terminal-error, .stripe-terminal-success, .stripe-terminal-info').hide();
      
      // Show the error message
      const messageDiv = jQuery('.stripe-terminal-error');
      if (messageDiv.length > 0) {
        messageDiv.find('p').text(message);
        messageDiv.show();
      }
    }
  }

  addToLog(message, type = 'info') {
    const textarea = jQuery('.stripe-terminal-log-textarea');
    if (textarea.length > 0) {
      const timestamp = new Date().toLocaleTimeString();
      const typePrefix = type.toUpperCase().padEnd(8);
      const logEntry = `[${timestamp}] ${typePrefix} ${message}\n`;
      
      // Append to textarea
      textarea.val(textarea.val() + logEntry);
      
      // Auto-scroll to bottom
      textarea.scrollTop(textarea[0].scrollHeight);
    }
  }

  // Method to get reader status (for display purposes)
  async getReaderStatus() {
    try {
      const response = await jQuery.ajax({
        url: this.ajaxUrl,
        type: 'POST',
        data: {
          action: 'stripe_terminal_get_reader_status',
          nonce: this.nonce
        }
      });
      
      if (response.success) {
        return response.data;
      } else {
        throw new Error(response.data || 'Failed to get reader status');
      }
    } catch (error) {
      console.error('Failed to get reader status:', error);
      return null;
    }
  }

  // Interface initialization
  async initializeInterface() {
    try {
      // Show loading state
      this.showLoading();
      
      // First validate the service
      const serviceValid = await this.validateService();
      if (!serviceValid) {
        this.showServiceError();
        return;
      }
      
      // Then fetch readers
      const readers = await this.fetchReaders();
      if (readers === null) {
        this.showReadersError();
        return;
      }
      
      // Update readers and show interface
      this.readers = readers;
      this.showInterface();
      
      // Load saved reader from localStorage
      this.loadSavedReader();
      
    } catch (error) {
      console.error('Failed to initialize interface:', error);
      this.showServiceError();
    }
  }

  async validateService() {
    try {
      const response = await jQuery.ajax({
        url: this.ajaxUrl,
        type: 'POST',
        data: {
          action: 'stripe_terminal_validate_service'
        }
      });
      
      return response.success;
    } catch (error) {
      console.error('Service validation failed:', error);
      return false;
    }
  }

  async fetchReaders() {
    try {
      const response = await jQuery.ajax({
        url: this.ajaxUrl,
        type: 'POST',
        data: {
          action: 'stripe_terminal_get_readers'
        }
      });
      
      console.log('Fetch readers response:', response);
      
      if (response.success) {
        console.log('Readers data:', response.data.readers);
        return response.data.readers;
      } else {
        console.error('Failed to fetch readers:', response.data);
        return null;
      }
    } catch (error) {
      console.error('Failed to fetch readers:', error);
      return null;
    }
  }

  showLoading() {
    jQuery('.stripe-terminal-loading').show();
    jQuery('.stripe-terminal-payment-section').hide();
  }

  showServiceError() {
    jQuery('.stripe-terminal-loading').hide();
    jQuery('.stripe-terminal-payment-section').hide();
    jQuery('.stripe-terminal-error').show().find('p').text(this.strings.serviceError || 'Service error');
  }

  showReadersError() {
    jQuery('.stripe-terminal-loading').hide();
    jQuery('.stripe-terminal-payment-section').show();
    jQuery('.stripe-terminal-error').show().find('p').text(this.strings.readersError || 'Failed to load readers');
  }

  showInterface() {
    jQuery('.stripe-terminal-loading').hide();
    jQuery('.stripe-terminal-payment-section').show();
    jQuery('.stripe-terminal-error').hide();
    
    // Small delay to ensure DOM is ready, then populate readers and update UI
    setTimeout(() => {
      this.populateReaders();
      this.updateReaderUI(); // This will show/hide sections based on connected reader
    }, 100);
  }

  populateReaders() {
    console.log('Populating readers:', this.readers);
    const readersList = jQuery('.stripe-terminal-readers-list');
    const readersSection = jQuery('.stripe-terminal-readers-section');
    console.log('Readers list element:', readersList);
    console.log('Readers list length:', readersList.length);
    
    if (readersList.length === 0) {
      console.error('Readers list element not found!');
      return;
    }
    
    readersList.empty();
    
    if (this.readers.length === 0) {
      console.log('No readers found, hiding readers section');
      readersSection.hide();
      return;
    }
    
    console.log(`Found ${this.readers.length} readers, creating cards`);
    this.readers.forEach((reader, index) => {
      console.log(`Creating card for reader ${index}:`, reader);
      const card = this.createReaderCard(reader);
      console.log('Created card:', card);
      readersList.append(card);
    });
    
    // Show the readers section when we have readers
    readersSection.show();
  }


  createReaderCard(reader) {
    const readerId = reader.id || '';
    const label = reader.label || readerId;
    const deviceType = reader.device_type || 'unknown';
    const status = reader.status || 'unknown';
    const serialNumber = reader.serial_number || '';
    const lastSeen = reader.last_seen_at || null;

    // Format last seen time
    let lastSeenText = '';
    if (lastSeen) {
      const lastSeenDate = new Date(lastSeen * 1000);
      lastSeenText = `Last seen: ${this.timeAgo(lastSeenDate)}`;
    }

    // Status indicator
    const statusClass = status === 'online' ? 'online' : 'offline';
    const statusText = status === 'online' ? 'Online' : 'Offline';

    return jQuery(`
      <div class="stripe-terminal-reader-card" data-reader-id="${readerId}">
        <div class="stripe-terminal-reader-header">
          <h5 class="stripe-terminal-reader-label">${label}</h5>
          <span class="stripe-terminal-reader-status ${statusClass}">${statusText}</span>
        </div>
        <div class="stripe-terminal-reader-details">
          <p><strong>Device:</strong> ${deviceType}</p>
          ${serialNumber ? `<p><strong>Serial:</strong> ${serialNumber}</p>` : ''}
          ${lastSeenText ? `<p><strong>${lastSeenText}</strong></p>` : ''}
        </div>
        <div class="stripe-terminal-reader-actions">
          ${status === 'online' 
            ? `<button type="button" class="stripe-terminal-connect-button" data-reader-id="${readerId}">Connect</button>`
            : `<button type="button" class="stripe-terminal-connect-button" disabled>Offline</button>`
          }
        </div>
      </div>
    `);
  }

  timeAgo(date) {
    const now = new Date();
    const diffInSeconds = Math.floor((now - date) / 1000);
    
    if (diffInSeconds < 60) return 'just now';
    if (diffInSeconds < 3600) return `${Math.floor(diffInSeconds / 60)} minutes ago`;
    if (diffInSeconds < 86400) return `${Math.floor(diffInSeconds / 3600)} hours ago`;
    return `${Math.floor(diffInSeconds / 86400)} days ago`;
  }

  // Reader management methods
  loadSavedReader() {
    const savedReaderId = localStorage.getItem('stripe_terminal_reader_id');
    if (savedReaderId) {
      const reader = this.readers.find(r => r.id === savedReaderId);
      if (reader && reader.status === 'online') {
        this.connectReader(reader);
      } else {
        // Clear invalid saved reader
        localStorage.removeItem('stripe_terminal_reader_id');
      }
    }
  }

  saveReader(readerId) {
    localStorage.setItem('stripe_terminal_reader_id', readerId);
  }

  clearSavedReader() {
    localStorage.removeItem('stripe_terminal_reader_id');
  }

  handleConnectReader(event) {
    event.preventDefault();
    
    const button = jQuery(event.target);
    const readerId = button.data('reader-id');
    
    if (!readerId) {
      this.showError('Invalid reader ID');
      return;
    }


    const reader = this.readers.find(r => r.id === readerId);
    if (!reader) {
      this.showError('Reader not found');
      return;
    }

    if (reader.status !== 'online') {
      this.showError('Reader is offline');
      return;
    }

    this.connectReader(reader);
  }

  connectReader(reader) {
    this.connectedReader = reader;
    this.saveReader(reader.id);
    
    // Update UI
    this.updateReaderUI();
    
    // Show success message
    this.showSuccess(`${this.strings.connected || 'Connected'} to ${reader.label || reader.id}`);
    
    console.log('Connected to reader:', reader);
  }

  handleDisconnectReader(event) {
    event.preventDefault();
    
    this.disconnectReader();
  }

  disconnectReader() {
    if (this.connectedReader) {
      const readerLabel = this.connectedReader.label || this.connectedReader.id;
      this.connectedReader = null;
      this.clearSavedReader();
      
      // Update UI
      this.updateReaderUI();
      
      // Show success message
      this.showSuccess(`${this.strings.disconnected || 'Disconnected'} from ${readerLabel}`);
      
      console.log('Disconnected from reader');
    }
  }

  updateReaderUI() {
    const readersSection = jQuery('.stripe-terminal-readers-section');
    const connectedSection = jQuery('.stripe-terminal-connected-reader');
    const paymentButtons = jQuery('.stripe-terminal-payment-buttons');

    if (this.connectedReader) {
      // Hide readers section, show connected reader
      readersSection.hide();
      connectedSection.show();
      paymentButtons.show();
      
      // Update connected reader info
      const reader = this.connectedReader;
      const readerDetails = jQuery('.stripe-terminal-reader-details');
      readerDetails.html(`
        <p><strong>${reader.label || reader.id}</strong></p>
        <p>Device: ${reader.device_type || 'Unknown'}</p>
        <p>Status: <span class="status-${reader.status || 'offline'}">${reader.status === 'online' ? 'Online' : 'Offline'}</span></p>
        ${reader.serial_number ? `<p>Serial: ${reader.serial_number}</p>` : ''}
      `);
      
      // Update button visibility
      this.updateButtonVisibility();
    } else {
      // Show readers section, hide connected reader
      readersSection.show();
      connectedSection.hide();
      paymentButtons.hide();
    }
  }

  getConnectedReaderId() {
    return this.connectedReader ? this.connectedReader.id : null;
  }

  handleToggleLog(event) {
    event.preventDefault();
    
    const button = jQuery(event.target);
    const logContent = jQuery('.stripe-terminal-log-content');
    const isExpanded = button.data('expanded') === true;
    
    if (isExpanded) {
      logContent.slideUp();
      button.text('Show Log').data('expanded', false);
    } else {
      logContent.slideDown();
      button.text('Hide Log').data('expanded', true);
    }
  }

  updateButtonVisibility() {
    if (!this.connectedReader) return;

    const simulateButton = jQuery('.stripe-terminal-simulate-button');
    const cancelButton = jQuery('.stripe-terminal-cancel-button');
    const retryButton = jQuery('.stripe-terminal-retry-button');

    if (this.connectedReader.device_type && this.connectedReader.device_type.includes('simulated') && this.currentPaymentIntent && !this.isDeclined) {
      simulateButton.show();
    } else {
      simulateButton.hide();
    }

    if (this.currentPaymentIntent) {
      cancelButton.show();
    } else {
      cancelButton.hide();
    }

    if (this.isDeclined && this.currentPaymentIntent) {
      retryButton.show();
    } else {
      retryButton.hide();
    }
  }



  handleClearLog(event) {
    event.preventDefault();
    const textarea = jQuery('.stripe-terminal-log-textarea');
    if (textarea.length > 0) {
      textarea.val('');
    }
  }
}

// Initialize when DOM is ready
jQuery(document).ready(function() {
  window.stripeTerminalPayment = new StripeTerminalPayment();
});

export default StripeTerminalPayment;
