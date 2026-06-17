(function () {
    'use strict';

    const globalConfig = window.DHKCSocialCardBuilder || {};

    class SocialCardBuilder {
        constructor(root) {
            this.root = root;
            this.config = {
                templateUrl: root.getAttribute('data-template-url') || globalConfig.templateUrl || '',
                downloadFileName: root.getAttribute('data-download-file-name') || globalConfig.downloadFileName || 'stagecard-social-card.png',
            };
            this.canvas = root.querySelector('.dhkc-card-builder__canvas');
            this.ctx = this.canvas.getContext('2d');
            this.fileInput = root.querySelector('.dhkc-card-builder__file');
            this.fileName = root.querySelector('[data-dhkc-file-name]');
            this.zoomInput = root.querySelector('.dhkc-card-builder__zoom');
            this.zoomButtons = root.querySelectorAll('[data-dhkc-zoom]');
            this.resetButton = root.querySelector('[data-dhkc-reset]');
            this.downloadButton = root.querySelector('[data-dhkc-download]');
            this.moveButtons = root.querySelectorAll('[data-dhkc-move]');

            this.template = new Image();
            this.template.crossOrigin = 'anonymous';
            this.templateLoaded = false;

            this.photo = null;
            this.photoState = {
                x: 0,
                y: 0,
                scale: 1,
                baseScale: 1,
            };

            this.hole = {
                x: 933.5,
                y: 525,
                radius: 216,
            };

            this.dragging = false;
            this.dragStart = null;

            this.bindEvents();
            this.loadTemplate();
        }

        bindEvents() {
            this.fileInput.addEventListener('change', (event) => this.handleFile(event));
            if (this.zoomInput) {
                this.zoomInput.addEventListener('input', () => this.handleZoom());
            }
            this.zoomButtons.forEach((button) => {
                button.addEventListener('click', () => this.handleZoomStep(button.getAttribute('data-dhkc-zoom')));
            });
            this.resetButton.addEventListener('click', () => this.resetPhoto());
            this.downloadButton.addEventListener('click', () => this.download());
            this.moveButtons.forEach((button) => {
                button.addEventListener('click', () => this.movePhoto(button.getAttribute('data-dhkc-move')));
            });

            this.canvas.addEventListener('pointerdown', (event) => this.startDrag(event));
            this.canvas.addEventListener('pointermove', (event) => this.moveDrag(event));
            this.canvas.addEventListener('pointerup', () => this.endDrag());
            this.canvas.addEventListener('pointercancel', () => this.endDrag());
            this.canvas.addEventListener('wheel', (event) => this.handleWheel(event), { passive: false });
        }

        loadTemplate() {
            this.template.onload = () => {
                this.canvas.width = this.template.naturalWidth || 1201;
                this.canvas.height = this.template.naturalHeight || 1201;
                this.templateLoaded = true;
                this.detectTransparentCircle();
                this.draw();
            };

            this.template.onerror = () => {
                this.drawMessage('Template image could not be loaded.');
            };

            this.template.src = this.config.templateUrl;
        }

        detectTransparentCircle() {
            const scan = document.createElement('canvas');
            scan.width = this.canvas.width;
            scan.height = this.canvas.height;
            const scanCtx = scan.getContext('2d');
            scanCtx.drawImage(this.template, 0, 0);

            const data = scanCtx.getImageData(0, 0, scan.width, scan.height).data;
            let minX = scan.width;
            let minY = scan.height;
            let maxX = 0;
            let maxY = 0;
            let found = false;

            for (let y = 0; y < scan.height; y += 2) {
                for (let x = 0; x < scan.width; x += 2) {
                    const alpha = data[(y * scan.width + x) * 4 + 3];
                    if (alpha < 20) {
                        minX = Math.min(minX, x);
                        minY = Math.min(minY, y);
                        maxX = Math.max(maxX, x);
                        maxY = Math.max(maxY, y);
                        found = true;
                    }
                }
            }

            if (found && maxX > minX && maxY > minY) {
                const width = maxX - minX;
                const height = maxY - minY;
                this.hole = {
                    x: minX + width / 2,
                    y: minY + height / 2,
                    radius: Math.min(width, height) / 2,
                };
            }
        }

        handleFile(event) {
            const file = event.target.files && event.target.files[0];
            if (this.fileName) {
                this.fileName.textContent = file ? file.name : 'No file chosen';
            }
            if (!file) {
                return;
            }

            const reader = new FileReader();
            reader.onload = () => {
                const image = new Image();
                image.onload = () => {
                    this.photo = image;
                    this.resetPhoto(false);
                    this.resetButton.disabled = false;
                    this.downloadButton.disabled = false;
                    if (this.zoomInput) { this.zoomInput.disabled = false; }
                    this.zoomButtons.forEach((button) => { button.disabled = false; });
                    this.moveButtons.forEach((button) => { button.disabled = false; });
                    this.draw();
                };
                image.src = reader.result;
            };
            reader.readAsDataURL(file);
        }

        resetPhoto(redraw = true) {
            if (!this.photo) {
                return;
            }

            const target = this.hole.radius * 2;
            const baseScale = Math.max(target / this.photo.width, target / this.photo.height);
            this.photoState = {
                x: this.hole.x,
                y: this.hole.y,
                scale: baseScale,
                baseScale: baseScale,
            };
            if (this.zoomInput) { this.zoomInput.value = '1'; }

            if (redraw) {
                this.draw();
            }
        }

        movePhoto(direction) {
            if (!this.photo) {
                return;
            }

            const amount = Math.max(8, Math.round(this.hole.radius * 0.035));
            if (direction === 'up') {
                this.photoState.y -= amount;
            }
            if (direction === 'down') {
                this.photoState.y += amount;
            }
            if (direction === 'left') {
                this.photoState.x -= amount;
            }
            if (direction === 'right') {
                this.photoState.x += amount;
            }
            this.draw();
        }

        handleZoom() {
            if (!this.photo || !this.zoomInput) {
                return;
            }
            this.photoState.scale = this.photoState.baseScale * parseFloat(this.zoomInput.value || '1');
            this.draw();
        }

        handleZoomStep(direction) {
            if (!this.photo || !this.zoomInput) {
                return;
            }
            const current = parseFloat(this.zoomInput.value || '1');
            const step = direction === 'in' ? 0.08 : -0.08;
            const next = Math.min(4, Math.max(0.25, current + step));
            this.zoomInput.value = String(next);
            this.handleZoom();
        }

        handleWheel(event) {
            if (!this.photo || !this.zoomInput) {
                return;
            }
            event.preventDefault();
            const current = parseFloat(this.zoomInput.value || '1');
            const next = Math.min(4, Math.max(0.25, current + (event.deltaY < 0 ? 0.05 : -0.05)));
            this.zoomInput.value = String(next);
            this.handleZoom();
        }

        startDrag(event) {
            if (!this.photo) {
                return;
            }
            this.dragging = true;
            this.canvas.setPointerCapture(event.pointerId);
            this.dragStart = {
                pointer: this.pointerPosition(event),
                x: this.photoState.x,
                y: this.photoState.y,
            };
        }

        moveDrag(event) {
            if (!this.dragging || !this.dragStart) {
                return;
            }
            const pointer = this.pointerPosition(event);
            this.photoState.x = this.dragStart.x + pointer.x - this.dragStart.pointer.x;
            this.photoState.y = this.dragStart.y + pointer.y - this.dragStart.pointer.y;
            this.draw();
        }

        endDrag() {
            this.dragging = false;
            this.dragStart = null;
        }

        pointerPosition(event) {
            const rect = this.canvas.getBoundingClientRect();
            return {
                x: (event.clientX - rect.left) * (this.canvas.width / rect.width),
                y: (event.clientY - rect.top) * (this.canvas.height / rect.height),
            };
        }

        draw() {
            if (!this.templateLoaded) {
                return;
            }

            this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);

            if (this.photo) {
                this.ctx.save();
                this.ctx.beginPath();
                this.ctx.arc(this.hole.x, this.hole.y, this.hole.radius, 0, Math.PI * 2);
                this.ctx.clip();

                const width = this.photo.width * this.photoState.scale;
                const height = this.photo.height * this.photoState.scale;
                this.ctx.drawImage(
                    this.photo,
                    this.photoState.x - width / 2,
                    this.photoState.y - height / 2,
                    width,
                    height
                );
                this.ctx.restore();
            }

            this.ctx.drawImage(this.template, 0, 0, this.canvas.width, this.canvas.height);
        }

        drawMessage(message) {
            this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
            this.ctx.fillStyle = '#071b2e';
            this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
            this.ctx.fillStyle = '#000000';
            this.ctx.font = '28px Montserrat, sans-serif';
            this.ctx.textAlign = 'center';
            this.ctx.fillText(message, this.canvas.width / 2, this.canvas.height / 2);
        }

        download() {
            if (!this.templateLoaded) {
                return;
            }
            this.draw();
            const link = document.createElement('a');
            link.href = this.canvas.toDataURL('image/png');
            link.download = this.config.downloadFileName || 'stagecard-social-card.png';
            link.click();
        }
    }

    function init() {
        document.querySelectorAll('[data-dhkc-card-builder]').forEach((root) => {
            if (!root.dataset.dhkcInitialized) {
                root.dataset.dhkcInitialized = '1';
                new SocialCardBuilder(root);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
