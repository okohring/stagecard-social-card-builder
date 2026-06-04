(function () {
    'use strict';

    const config = window.DHKCSocialCardBuilder || {};

    class SocialCardBuilder {
        constructor(root) {
            this.root = root;
            this.canvas = root.querySelector('.dhkc-card-builder__canvas');
            this.ctx = this.canvas.getContext('2d');
            this.fileInput = root.querySelector('.dhkc-card-builder__file');
            this.zoomInput = root.querySelector('.dhkc-card-builder__zoom');
            this.resetButton = root.querySelector('[data-dhkc-reset]');
            this.downloadButton = root.querySelector('[data-dhkc-download]');

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
            this.zoomInput.addEventListener('input', () => this.handleZoom());
            this.resetButton.addEventListener('click', () => this.resetPhoto());
            this.downloadButton.addEventListener('click', () => this.download());

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

            this.template.src = config.templateUrl;
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
            let count = 0;

            for (let y = 0; y < scan.height; y += 1) {
                for (let x = 0; x < scan.width; x += 1) {
                    const alpha = data[(y * scan.width + x) * 4 + 3];
                    if (alpha < 10) {
                        minX = Math.min(minX, x);
                        minY = Math.min(minY, y);
                        maxX = Math.max(maxX, x);
                        maxY = Math.max(maxY, y);
                        count += 1;
                    }
                }
            }

            if (count > 1000) {
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
            if (!file || !file.type.match(/^image\//)) {
                return;
            }

            const reader = new FileReader();
            reader.onload = () => {
                const img = new Image();
                img.onload = () => {
                    this.photo = img;
                    this.fitPhotoToCircle();
                    this.enablePhotoControls(true);
                    this.draw();
                };
                img.src = reader.result;
            };
            reader.readAsDataURL(file);
        }

        fitPhotoToCircle() {
            if (!this.photo) {
                return;
            }

            const diameter = this.hole.radius * 2;
            const baseScale = Math.max(diameter / this.photo.naturalWidth, diameter / this.photo.naturalHeight);

            this.photoState.baseScale = baseScale;
            this.photoState.scale = baseScale;
            this.photoState.x = this.hole.x;
            this.photoState.y = this.hole.y;

            this.zoomInput.min = (baseScale * 0.75).toFixed(4);
            this.zoomInput.max = (baseScale * 4).toFixed(4);
            this.zoomInput.step = (baseScale / 100).toFixed(4);
            this.zoomInput.value = baseScale.toFixed(4);
        }

        enablePhotoControls(enabled) {
            this.zoomInput.disabled = !enabled;
            this.resetButton.disabled = !enabled;
            this.downloadButton.disabled = !enabled;
        }

        handleZoom() {
            if (!this.photo) {
                return;
            }
            this.photoState.scale = parseFloat(this.zoomInput.value);
            this.draw();
        }

        handleWheel(event) {
            if (!this.photo) {
                return;
            }

            event.preventDefault();
            const current = parseFloat(this.zoomInput.value);
            const step = Math.max(this.photoState.baseScale / 8, 0.01);
            const next = event.deltaY < 0 ? current + step : current - step;
            const min = parseFloat(this.zoomInput.min);
            const max = parseFloat(this.zoomInput.max);
            const clamped = Math.min(max, Math.max(min, next));

            this.zoomInput.value = clamped.toFixed(4);
            this.photoState.scale = clamped;
            this.draw();
        }

        canvasPoint(event) {
            const rect = this.canvas.getBoundingClientRect();
            return {
                x: (event.clientX - rect.left) * (this.canvas.width / rect.width),
                y: (event.clientY - rect.top) * (this.canvas.height / rect.height),
            };
        }

        startDrag(event) {
            if (!this.photo) {
                return;
            }

            this.dragging = true;
            this.canvas.setPointerCapture(event.pointerId);
            const point = this.canvasPoint(event);
            this.dragStart = {
                pointerX: point.x,
                pointerY: point.y,
                photoX: this.photoState.x,
                photoY: this.photoState.y,
            };
        }

        moveDrag(event) {
            if (!this.dragging || !this.dragStart) {
                return;
            }

            const point = this.canvasPoint(event);
            this.photoState.x = this.dragStart.photoX + point.x - this.dragStart.pointerX;
            this.photoState.y = this.dragStart.photoY + point.y - this.dragStart.pointerY;
            this.draw();
        }

        endDrag() {
            this.dragging = false;
            this.dragStart = null;
        }

        resetPhoto() {
            this.fitPhotoToCircle();
            this.draw();
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

                const width = this.photo.naturalWidth * this.photoState.scale;
                const height = this.photo.naturalHeight * this.photoState.scale;
                const x = this.photoState.x - width / 2;
                const y = this.photoState.y - height / 2;

                this.ctx.drawImage(this.photo, x, y, width, height);
                this.ctx.restore();
            }

            this.ctx.drawImage(this.template, 0, 0, this.canvas.width, this.canvas.height);
        }

        drawMessage(message) {
            this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
            this.ctx.fillStyle = '#071b2e';
            this.ctx.fillRect(0, 0, this.canvas.width, this.canvas.height);
            this.ctx.fillStyle = '#ffffff';
            this.ctx.font = '32px sans-serif';
            this.ctx.textAlign = 'center';
            this.ctx.fillText(message, this.canvas.width / 2, this.canvas.height / 2);
        }

        download() {
            if (!this.photo) {
                return;
            }

            this.draw();
            const link = document.createElement('a');
            link.download = config.downloadFileName || 'social-card.png';
            link.href = this.canvas.toDataURL('image/png');
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    }

    function init() {
        document.querySelectorAll('[data-dhkc-card-builder]').forEach((root) => {
            new SocialCardBuilder(root);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
