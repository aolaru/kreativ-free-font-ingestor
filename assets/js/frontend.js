const config = window.KFIPreviewConfig || {};

const previewInput = document.querySelector('.kfi-preview-input');
const previewSize = document.querySelector('.kfi-preview-size');
const previewSample = document.querySelector('.kfi-preview-sample');
const previewReadout = document.querySelector('.kfi-preview-size-readout');
const previewStage = document.querySelector('.kfi-preview-stage');
const previewPresets = document.querySelectorAll('.kfi-preview-preset');
const fontFamilyTargets = document.querySelectorAll('[data-font-family]');

if (previewInput && previewSample) {
	const syncPreviewText = () => {
		const value = previewInput.value.trim();
		previewSample.textContent = value || 'Abc 123';
	};

	previewInput.addEventListener('input', syncPreviewText);
	syncPreviewText();
}

if (previewPresets.length && previewInput) {
	previewPresets.forEach((button) => {
		button.addEventListener('click', () => {
			previewPresets.forEach((item) => item.classList.remove('is-active'));
			button.classList.add('is-active');
			previewInput.value = button.dataset.previewText || '';
			previewInput.dispatchEvent(new Event('input', { bubbles: true }));
		});
	});
}

if (previewSize && previewSample && previewReadout) {
	const syncPreviewSize = () => {
		const value = `${previewSize.value}px`;
		previewSample.style.fontSize = value;
		previewReadout.textContent = value;
	};

	previewSize.addEventListener('input', syncPreviewSize);
	syncPreviewSize();
}

const appliedFontFamily = config.previewAlias || config.fontFamily || '';

if (fontFamilyTargets.length && appliedFontFamily) {
	fontFamilyTargets.forEach((element) => {
		element.style.fontFamily = `"${appliedFontFamily}", sans-serif`;
	});
}
