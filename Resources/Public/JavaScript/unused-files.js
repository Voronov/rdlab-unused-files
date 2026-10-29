/**
 * Backend module "Unused files": Gmail-like selection.
 *
 * - header checkbox selects all files on the current page
 * - a banner then offers to select all files matching the filter (hidden input selectAll=1)
 * - the "Delete selected" button shows the number of selected files and carries
 *   the matching confirmation text for the core's ".t3js-modal-trigger" dialog
 *
 * Importing modal.js makes sure the declarative modal trigger is initialised for this document.
 */
import '@typo3/backend/modal.js';

class UnusedFiles {
  constructor() {
    this.form = document.getElementById('rdlab-unused-files-form');
    if (!this.form) {
      return;
    }
    this.toggleAll = this.form.querySelector('[data-rdlab-toggle-all]');
    this.checkboxes = Array.from(this.form.querySelectorAll('input[name="files[]"]'));
    this.selectAllInput = this.form.querySelector('input[name="selectAll"]');
    this.banner = this.form.querySelector('[data-rdlab-selection-banner]');
    this.bannerText = this.form.querySelector('[data-rdlab-selection-text]');
    this.bannerAction = this.form.querySelector('[data-rdlab-selection-action]');
    this.deleteButton = this.form.querySelector('[data-rdlab-delete-selected]');
    this.deleteLabel = this.form.querySelector('[data-rdlab-delete-selected-label]');
    this.total = parseInt(this.form.dataset.total || '0', 10);
    this.bulkDeleteLimit = parseInt(this.form.dataset.bulkDeleteLimit || '0', 10);
    this.labels = {
      bannerPage: this.form.dataset.labelBannerPage || 'All %d files on this page are selected.',
      bannerSelectAll: this.form.dataset.labelBannerSelectAll || 'Select all %d files matching the filter',
      bannerAll: this.form.dataset.labelBannerAll || 'All %d files matching the filter are selected.',
      bannerClear: this.form.dataset.labelBannerClear || 'Clear selection',
      confirmSelected: this.form.dataset.labelConfirmSelected || 'Delete %d selected files?',
      confirmAll: this.form.dataset.labelConfirmAll || 'Delete all %d files matching the filter? At most %d files are deleted per run.',
      button: this.form.dataset.labelButton || 'Delete selected',
    };

    this.toggleAll?.addEventListener('change', () => {
      this.checkboxes.forEach((checkbox) => { checkbox.checked = this.toggleAll.checked; });
      this.setSelectAll(false);
      this.update();
    });
    this.checkboxes.forEach((checkbox) => {
      checkbox.addEventListener('change', () => {
        if (!checkbox.checked) {
          this.setSelectAll(false);
        }
        this.update();
      });
    });
    this.bannerAction?.addEventListener('click', (event) => {
      event.preventDefault();
      if (this.isSelectAll()) {
        this.setSelectAll(false);
        this.checkboxes.forEach((checkbox) => { checkbox.checked = false; });
      } else {
        this.setSelectAll(true);
      }
      this.update();
    });

    this.update();
  }

  isSelectAll() {
    return this.selectAllInput?.value === '1';
  }

  setSelectAll(enabled) {
    if (this.selectAllInput) {
      this.selectAllInput.value = enabled ? '1' : '0';
    }
  }

  format(template, ...values) {
    let index = 0;
    return template.replace(/%d/g, () => String(values[index++] ?? ''));
  }

  update() {
    const selectedOnPage = this.checkboxes.filter((checkbox) => checkbox.checked).length;
    const allOnPage = this.checkboxes.length > 0 && selectedOnPage === this.checkboxes.length;
    const selectAll = this.isSelectAll();

    if (this.toggleAll) {
      this.toggleAll.checked = allOnPage;
      this.toggleAll.indeterminate = selectedOnPage > 0 && !allOnPage;
    }

    if (this.banner) {
      const showBanner = allOnPage && this.total > this.checkboxes.length;
      this.banner.classList.toggle('d-none', !showBanner);
      if (showBanner) {
        this.bannerText.textContent = selectAll
          ? this.format(this.labels.bannerAll, this.total)
          : this.format(this.labels.bannerPage, selectedOnPage);
        this.bannerAction.textContent = selectAll
          ? this.labels.bannerClear
          : this.format(this.labels.bannerSelectAll, this.total);
      }
    }

    if (this.deleteButton) {
      const count = selectAll ? this.total : selectedOnPage;
      this.deleteButton.disabled = count === 0;
      this.deleteButton.dataset.bsContent = selectAll
        ? this.format(this.labels.confirmAll, this.total, this.bulkDeleteLimit)
        : this.format(this.labels.confirmSelected, count);
      if (this.deleteLabel) {
        this.deleteLabel.textContent = count > 0 ? `${this.labels.button} (${count})` : this.labels.button;
      }
    }
  }
}

export default new UnusedFiles();
