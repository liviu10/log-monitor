const App = {
    handleToast: function (toastContent) {
        const { type, title, message, toastDelay, redirectUrl } = toastContent;
        const toastSymbols = {
            danger: '🔥',
            warning: '⚠️',
            success: '✅',
            notice: 'ℹ️',
            info: '💡',
        };
        const symbol = toastSymbols[type] || '';

        let toastContainer = document.querySelector(".toast-container-main");
        
        if (!toastContainer) {
            toastContainer = document.createElement("div");
            toastContainer.className = "toast-container-main position-fixed top-0 end-0 p-3";
            toastContainer.style.zIndex = "1090";
            document.body.appendChild(toastContainer);
        }

        const toastEl = document.createElement("div");
        toastEl.className = `toast border border-2 border-${type}`;
        toastEl.setAttribute("role", "alert");
        toastEl.setAttribute("aria-live", "assertive");
        toastEl.setAttribute("aria-atomic", "true");

        const toastHeader = document.createElement("div");
        toastHeader.className = "toast-header fw-bold";
        toastHeader.innerHTML = `
            <strong class="me-auto">${symbol} ${title}</strong>
            <button type="button" class="btn-close" data-bs-dismiss="toast" aria-label="Close"></button>
        `;

        const toastBody = document.createElement("div");
        toastBody.className = "toast-body";
        toastBody.innerHTML = message;

        toastEl.appendChild(toastHeader);

        // Bara de progres (Timer)
        if (toastDelay > 0) {
            const progress = document.createElement("div");
            progress.className = `toast-progress-bar bg-${type}`;
            progress.style.height = "4px";
            progress.style.width = "100%";
            progress.style.transition = "width linear";
            toastEl.appendChild(progress);

            setTimeout(() => {
                progress.style.transitionDuration = `${toastDelay}ms`;
                progress.style.width = "0%";
            }, 50);
        }

        toastEl.appendChild(toastBody);
        toastContainer.appendChild(toastEl);

        const toastOptions = {
            autohide: toastDelay !== 0
        };

        if (toastDelay > 0) {
            toastOptions.delay = toastDelay;
        }

        const toast = new bootstrap.Toast(toastEl, toastOptions);
        toast.show();

        toastEl.addEventListener("hidden.bs.toast", function () {
            toast.dispose();
            toastEl.remove();
            if (redirectUrl) {
                window.location.href = redirectUrl;
            }
        });
    }
};
