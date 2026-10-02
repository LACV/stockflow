import './bootstrap';

// Handle month closed alerts from form fields
document.addEventListener('show-month-closed-alert', (event) => {
    // Create a toast element
    const toast = document.createElement('div');
    toast.className = 'fixed bottom-4 right-4 bg-red-500 text-white px-4 py-2 rounded shadow-lg z-50';
    toast.textContent = event.detail.message;
    document.body.appendChild(toast);

    // Remove after 3 seconds
    setTimeout(() => {
        toast.remove();
    }, 3000);
});
