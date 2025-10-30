
        window.RoutinePage = <?php echo json_encode($pageState, $jsonOptions); ?>;
    </script>
    <script>
        (function() {
            const page = window.RoutinePage || {
                tasks: [],
                createInitial: [],
                editInitial: {},
                routines: [],
                preferences: { adaptive_warnings_enabled: 1, sub_timer_label: 'hurry_goal' },
                subTimerLabels: {}
            };

            const taskLookup = new Map((Array.isArray(page.tasks) ? page.tasks : []).map(task => [String(task.id), task]));

            function parseTimeParts(value) {
                if (!value) return null;
                const parts = value.split(':').map(Number);
                if (parts.length < 2 || parts.some(Number.isNaN)) return null;
                return { hours: parts[0], minutes: parts[1] };
            }

            function calculateDurationSeconds(start, end) {
                const startParts = parseTimeParts(start);
                const endParts = parseTimeParts(end);
                if (!startParts || !endParts) return null;
                const startSeconds = startParts.hours * 3600 + startParts.minutes * 60;
                let endSeconds = endParts.hours * 3600 + endParts.minutes * 60;
                if (endSeconds <= startSeconds) {
                    endSeconds += 24 * 3600;
                }
                return endSeconds - startSeconds;
            }

            function formatSeconds(totalSeconds) {
                const safe = Math.max(0, Math.floor(totalSeconds));
                const minutes = Math.floor(safe / 60);
                const seconds = safe % 60;
                return `${minutes}:${seconds.toString().padStart(2, '0')}`;
            }

            function formatSignedSeconds(seconds) {
                if (seconds >= 0) {
                    return formatSeconds(seconds);
                }
                return `-${formatSeconds(Math.abs(seconds))}`;
            }

            class RoutineBuilder {
                constructor(container, initialTasks) {
                    this.container = container;
                    this.listEl = container.querySelector('[data-role="selected-list"]');
                    this.taskPicker = container.querySelector('[data-role="task-picker"]');
                    this.addButton = container.querySelector('[data-role="add-task"]');
                    this.structureInput = container.querySelector('[data-role="structure-input"]');
                    this.totalMinutesEl = container.querySelector('[data-role="total-minutes"]');
                    this.durationEl = container.querySelector('[data-role="duration-minutes"]');
                    this.warningEl = container.querySelector('[data-role="warning"]');
                    this.startInput = this.resolveInput(container.dataset.startInput);
                    this.endInput = this.resolveInput(container.dataset.endInput);
                    this.selectedTasks = Array.isArray(initialTasks)
                        ? initialTasks.map(task => ({
                            id: parseInt(task.id, 10),
                            dependency_id: task.dependency_id !== null ? parseInt(task.dependency_id, 10) : null
                        })).filter(task => task.id > 0)
                        : [];
                    this.setup();
                }

                resolveInput(selector) {
                    if (!selector) return null;
                    if (selector.startsWith('input')) {
                        const form = this.container.closest('form');
                        return form ? form.querySelector(selector) : document.querySelector(selector);
                    }
                    return document.querySelector(selector);
                }

                setup() {
                    if (this.addButton && this.taskPicker) {
                        this.addButton.addEventListener('click', () => {
                            const value = this.taskPicker.value;
                            if (!value) return;
                            const numeric = parseInt(value, 10);
                            if (Number.isNaN(numeric)) return;
                            if (this.selectedTasks.some(task => task.id === numeric)) return;
                            this.selectedTasks.push({ id: numeric, dependency_id: null });
                            this.render();
                        });
                    }

                    if (this.startInput) {
                        this.startInput.addEventListener('change', () => this.updateSummary());
                    }
                    if (this.endInput) {
                        this.endInput.addEventListener('change', () => this.updateSummary());
                    }

                    if (this.listEl) {
                        new Sortable(this.listEl, {
                            animation: 150,
                            handle: '.drag-handle',
                            onEnd: () => {
                                const order = Array.from(this.listEl.querySelectorAll('.selected-task-item'))
                                    .map(item => parseInt(item.dataset.taskId, 10));
                                const reordered = [];
                                order.forEach(id => {
                                    const existing = this.selectedTasks.find(task => task.id === id);
                                    if (existing) {
                                        reordered.push(existing);
                                    }
                                });
                                this.selectedTasks = reordered;
                                this.render();
                            }
                        });
                    }

                    this.render();
                }

                render() {
                    if (!this.listEl) return;
                    this.listEl.innerHTML = '';

                    this.selectedTasks.forEach((task, index) => {
                        const taskData = taskLookup.get(String(task.id));
                        if (!taskData) return;

                        const item = document.createElement('li');
                        item.className = 'selected-task-item';
                        item.dataset.taskId = String(task.id);

                        const handle = document.createElement('span');
                        handle.className = 'drag-handle';
                        handle.textContent = String.fromCharCode(0x283F);

                        const body = document.createElement('div');
                        const title = document.createElement('div');
                        title.innerHTML = `<strong>${taskData.title}</strong>`;
                        const meta = document.createElement('div');
                        meta.className = 'task-meta';
                        meta.textContent = `${taskData.time_limit || 0} min ${String.fromCharCode(0x2022)} ${taskData.category}`;
                        const dependencyWrapper = document.createElement('div');
                        dependencyWrapper.className = 'dependency-select';
                        const label = document.createElement('label');
                        label.textContent = 'Depends on:';
                        const select = document.createElement('select');
                        const noneOption = document.createElement('option');
                        noneOption.value = '';
                        noneOption.textContent = 'None';
                        select.appendChild(noneOption);
                        for (let i = 0; i < index; i++) {
                            const allowedTask = this.selectedTasks[i];
                            const allowedData = taskLookup.get(String(allowedTask.id));
                            if (!allowedData) continue;
                            const option = document.createElement('option');
                            option.value = String(allowedTask.id);
                            option.textContent = allowedData.title;
                            if (allowedTask.id === task.dependency_id) {
                                option.selected = true;
                            }
                            select.appendChild(option);
                        }
                        if (task.dependency_id !== null) {
                            select.value = String(task.dependency_id);
                        } else {
                            select.value = '';
                        }
                        select.addEventListener('change', () => {
                            task.dependency_id = select.value !== '' ? parseInt(select.value, 10) : null;
                        });
                        dependencyWrapper.appendChild(label);
                        dependencyWrapper.appendChild(select);

                        body.appendChild(title);
                        body.appendChild(meta);
                        body.appendChild(dependencyWrapper);

                        const removeButton = document.createElement('button');
                        removeButton.type = 'button';
                        removeButton.className = 'button danger';
                        removeButton.textContent = 'Remove';
                        removeButton.addEventListener('click', () => {
                            this.selectedTasks = this.selectedTasks.filter(selected => selected.id !== task.id);
                            this.render();
                        });

                        item.appendChild(handle);
                        item.appendChild(body);
                        item.appendChild(removeButton);
                        this.listEl.appendChild(item);
                    });

                    this.syncStructureInput();
                    this.updateSummary();
                }

                syncStructureInput() {
                    if (!this.structureInput) return;
                    const payload = {
                        tasks: this.selectedTasks.map(task => ({
                            id: task.id,
                            dependency_id: task.dependency_id
                        }))
                    };
                    this.structureInput.value = JSON.stringify(payload);
                }

                updateSummary() {
                    const totalMinutes = this.selectedTasks.reduce((sum, task) => {
                        const data = taskLookup.get(String(task.id));
                        return sum + (data ? (parseInt(data.time_limit, 10) || 0) : 0);
                    }, 0);
                    const durationSeconds = calculateDurationSeconds(this.startInput ? this.startInput.value : '', this.endInput ? this.endInput.value : '');
                    const durationMinutes = durationSeconds !== null ? Math.round(durationSeconds / 60) : null;
                    if (this.totalMinutesEl) {
                        this.totalMinutesEl.textContent = totalMinutes;
                    }
                    if (this.durationEl) {
                        this.durationEl.textContent = durationMinutes !== null ? durationMinutes : '--';
                    }
                    if (this.warningEl) {
                        if (durationMinutes !== null && totalMinutes > durationMinutes) {
                            this.warningEl.textContent = 'Total task time exceeds routine duration.';
                            this.container.classList.add('warning-active');
                        } else {
                            this.warningEl.textContent = '';
                            this.container.classList.remove('warning-active');
                        }
                    }
                }
            }

            
                init() {
                    if (this.startButton) {
                        this.startButton.addEventListener('click', () => this.startRoutine());
                    }
                    if (this.finishButton) {
                        this.finishButton.addEventListener('click', () => this.finishTask());
                    }
                }

            class RoutinePlayer {
                constructor(card, routine, preferences, labelMap) {
                    this.card = card;
                    this.routine = routine;
                    this.preferences = preferences || {};
                    this.subTimerLabelMap = labelMap || {};
                    this.tasks = Array.isArray(routine.tasks) ? [...routine.tasks] : [];
                    this.tasks.sort((a, b) => (parseInt(a.sequence_order, 10) || 0) - (parseInt(b.sequence_order, 10) || 0));

                    this.openButton = card.querySelector("[data-action='open-flow']");
                    this.overlay = card.querySelector("[data-role='routine-flow']");
                    this.flowTitleEl = this.overlay ? this.overlay.querySelector("[data-role='flow-title']") : null;
                    this.nextLabelEl = this.overlay ? this.overlay.querySelector("[data-role='flow-next-label']") : null;
                    this.progressFillEl = this.overlay ? this.overlay.querySelector("[data-role='flow-progress']") : null;
                    this.countdownEl = this.overlay ? this.overlay.querySelector("[data-role='flow-countdown']") : null;
                    this.limitLabelEl = this.overlay ? this.overlay.querySelector("[data-role='flow-limit']") : null;
                    this.statusPointsEl = this.overlay ? this.overlay.querySelector("[data-role='status-points']") : null;
                    this.statusTimeEl = this.overlay ? this.overlay.querySelector("[data-role='status-time']") : null;
                    this.statusFeedbackEl = this.overlay ? this.overlay.querySelector("[data-role='status-feedback']") : null;
                    this.statusStars = this.overlay ? Array.from(this.overlay.querySelectorAll('.status-stars span')) : [];
                    this.summaryListEl = this.overlay ? this.overlay.querySelector("[data-role='summary-list']") : null;
                    this.summaryTotalEl = this.overlay ? this.overlay.querySelector("[data-role='summary-total']") : null;
                    this.summaryAccountEl = this.overlay ? this.overlay.querySelector("[data-role='summary-account-total']") : null;
                    this.summaryBonusEl = this.overlay ? this.overlay.querySelector("[data-role='summary-bonus']") : null;

                    this.sceneMap = new Map();
                    if (this.overlay) {
                        this.overlay.querySelectorAll('.routine-scene').forEach(scene => {
                            this.sceneMap.set(scene.dataset.scene, scene);
                        });
                    }
                    this.exitButton = this.overlay ? this.overlay.querySelector("[data-action='flow-exit']") : null;
                    this.completeButton = this.overlay ? this.overlay.querySelector("[data-action='flow-complete-task']") : null;
                    this.statusNextButton = this.overlay ? this.overlay.querySelector("[data-action='flow-next-task']") : null;
                    this.finishButton = this.overlay ? this.overlay.querySelector("[data-action='flow-finish']") : null;

                    this.currentIndex = 0;
                    this.currentTask = null;
                    this.taskStartTime = null;
                    this.elapsedSeconds = 0;
                    this.scheduledSeconds = 0;
                    this.taskAnimationFrame = null;
                    this.overtimeBuffer = [];
                    this.taskResults = [];
                    this.totalEarnedPoints = 0;
                    this.allWithinLimit = true;
                    this.childPoints = typeof page.childPoints === 'number' ? page.childPoints : 0;

                    this.init();
                }

                init() {
                    if (!this.openButton || !this.overlay) {
                        return;
                    }
                    this.openButton.addEventListener('click', () => this.openFlow());
                    if (this.exitButton) {
                        this.exitButton.addEventListener('click', () => this.handleExit());
                    }
                    if (this.completeButton) {
                        this.completeButton.addEventListener('click', () => this.handleTaskComplete());
                    }
                    if (this.statusNextButton) {
                        this.statusNextButton.addEventListener('click', () => this.advanceFromStatus());
                    }
                    if (this.finishButton) {
                        this.finishButton.addEventListener('click', () => this.closeOverlay(false));
                    }
                    this.updateNextLabel();
                }

                openFlow() {
                    if (!this.tasks.length) {
                        alert('No tasks are available in this routine yet.');
                        return;
                    }
                    this.overlay.classList.add('active');
                    this.overlay.setAttribute('aria-hidden', 'false');
                    this.startRoutine();
                }

                handleExit() {
                    this.closeOverlay();
                    this.resetRoutineStatuses();
                    this.tasks.forEach(task => this.markTaskPending(task.id));
                    this.taskResults = [];
                    this.totalEarnedPoints = 0;
                    this.overtimeBuffer = [];
                    if (this.openButton) {
                        this.openButton.textContent = 'Start Routine';
                    }
                }

                closeOverlay(resetTitle = true) {
                    this.stopTaskAnimation();
                    if (this.overlay) {
                        this.overlay.classList.remove('active');
                        this.overlay.setAttribute('aria-hidden', 'true');
                    }
                    if (resetTitle && this.flowTitleEl) {
                        this.flowTitleEl.textContent = this.routine.title || 'Routine';
                    }
                    if (this.summaryBonusEl) {
                        this.summaryBonusEl.textContent = '';
                    }
                }

                startRoutine() {
                    this.resetRoutineStatuses();
                    this.tasks.forEach(task => {
                        task.status = 'pending';
                        this.markTaskPending(task.id);
                    });
                    this.taskResults = [];
                    this.totalEarnedPoints = 0;
                    this.overtimeBuffer = [];
                    this.allWithinLimit = true;
                    this.currentIndex = 0;
                    this.childPoints = typeof page.childPoints === 'number' ? page.childPoints : this.childPoints;
                    if (this.openButton) {
                        this.openButton.textContent = 'Restart Routine';
                    }
                    this.showScene('task');
                    this.startTask(this.currentIndex);
                }

                startTask(index) {
                    if (index >= this.tasks.length) {
                        this.displaySummary();
                        return;
                    }
                    this.currentTask = this.tasks[index];
                    this.scheduledSeconds = Math.max(0, (parseInt(this.currentTask.time_limit, 10) || 0) * 60);
                    this.taskStartTime = null;
                    this.elapsedSeconds = 0;
                    this.stopTaskAnimation();
                    this.updateTaskHeader();
                    this.updateNextLabel();
                    this.updateTimeLimitLabel();
                    this.updateProgressDisplay();
                    this.startTaskAnimation();
                }

                startTaskAnimation() {
                    const step = (timestamp) => {
                        if (this.taskStartTime === null) {
                            this.taskStartTime = timestamp;
                        }
                        const elapsedMs = timestamp - this.taskStartTime;
                        this.elapsedSeconds = elapsedMs / 1000;
                        this.updateProgressDisplay();
                        this.taskAnimationFrame = requestAnimationFrame(step);
                    };
                    this.taskAnimationFrame = requestAnimationFrame(step);
                }

                stopTaskAnimation() {
                    if (this.taskAnimationFrame) {
                        cancelAnimationFrame(this.taskAnimationFrame);
                        this.taskAnimationFrame = null;
                    }
                }

                updateProgressDisplay() {
                    if (!this.progressFillEl) return;
                    const scheduled = this.scheduledSeconds;
                    let progress = 1;
                    let remainingText = '--:--';
                    if (scheduled > 0) {
                        const remaining = Math.max(0, scheduled - this.elapsedSeconds);
                        progress = Math.min(1, this.elapsedSeconds / scheduled);
                        remainingText = formatSeconds(Math.ceil(remaining));
                    }
                    this.progressFillEl.style.transform = `scaleX(${progress})`;
                    if (this.countdownEl) {
                        this.countdownEl.textContent = remainingText;
                    }
                }

                updateTimeLimitLabel() {
                    if (!this.limitLabelEl) return;
                    if (this.scheduledSeconds > 0) {
                        this.limitLabelEl.textContent = `Time Limit: ${formatSeconds(this.scheduledSeconds)}`;
                    } else {
                        this.limitLabelEl.textContent = 'Time Limit: --';
                    }
                }

                handleTaskComplete() {
                    if (!this.currentTask) return;
                    this.stopTaskAnimation();
                    const actualSeconds = Math.ceil(Math.max(0, this.elapsedSeconds));
                    const scheduled = this.scheduledSeconds;
                    const pointValue = parseInt(this.currentTask.point_value, 10) || 0;
                    if (scheduled > 0 && actualSeconds > scheduled) {
                        this.overtimeBuffer.push({
                            routine_id: parseInt(this.routine.id, 10) || 0,
                            routine_task_id: parseInt(this.currentTask.id, 10) || 0,
                            child_user_id: parseInt(this.routine.child_user_id, 10) || 0,
                            scheduled_seconds: scheduled,
                            actual_seconds: actualSeconds,
                            overtime_seconds: actualSeconds - scheduled
                        });
                        this.allWithinLimit = false;
                    }
                    const awardedPoints = calculateRoutineTaskAwardPoints(pointValue, scheduled, actualSeconds);
                    if (scheduled > 0 && actualSeconds > scheduled) {
                        this.allWithinLimit = false;
                    }
                    this.totalEarnedPoints += awardedPoints;
                    this.taskResults.push({
                        id: parseInt(this.currentTask.id, 10) || 0,
                        title: this.currentTask.title,
                        point_value: pointValue,
                        actual_seconds: actualSeconds,
                        scheduled_seconds: scheduled,
                        awarded_points: awardedPoints
                    });

                    this.updateTaskStatus(this.currentTask.id, 'completed');
                    this.currentTask.status = 'completed';
                    this.markTaskCompleted(this.currentTask.id);
                    this.presentStatus(awardedPoints, actualSeconds, scheduled);
                    this.currentIndex += 1;
                }

                presentStatus(points, actualSeconds, scheduledSeconds) {
                    if (this.statusPointsEl) {
                        this.statusPointsEl.textContent = `+${points} points`;
                    }
                    if (this.statusTimeEl) {
                        this.statusTimeEl.textContent = `You finished in ${formatSeconds(actualSeconds)}.`;
                    }
                    if (this.statusFeedbackEl) {
                        let feedback = 'Nice work!';
                        let stars = 1;
                        if (scheduledSeconds <= 0) {
                            feedback = 'No timer on this task—keep up the pace!';
                            stars = 3;
                        } else {
                            const ratio = actualSeconds / Math.max(1, scheduledSeconds);
                            if (ratio <= 1) {
                                feedback = 'Right on time!';
                                stars = 3;
                            } else if (ratio <= 1.4) {
                                feedback = 'A little late—half points earned.';
                                stars = 2;
                            } else {
                                feedback = 'Over the limit—no points this time.';
                                stars = 1;
                            }
                        }
                        this.statusFeedbackEl.textContent = feedback;
                        this.statusStars.forEach((star, idx) => {
                            if (idx < stars) {
                                star.classList.add('active');
                            } else {
                                star.classList.remove('active');
                            }
                        });
                    }
                    this.showScene('status');
                    if (this.statusNextButton) {
                        const label = this.currentIndex + 1 < this.tasks.length ? 'Next Task' : 'Summary';
                        this.statusNextButton.textContent = label;
                    }
                }

                advanceFromStatus() {
                    if (this.currentIndex < this.tasks.length) {
                        this.showScene('task');
                        this.startTask(this.currentIndex);
                    } else {
                        this.displaySummary();
                    }
                }

                displaySummary() {
                    this.showScene('summary');
                    this.renderSummary(this.taskResults);
                    this.finalizeRoutine();
                }

                renderSummary(results) {
                    if (this.summaryListEl) {
                        this.summaryListEl.innerHTML = '';
                        results.forEach(result => {
                            const card = document.createElement('div');
                            card.className = 'summary-card';
                            const title = document.createElement('span');
                            title.textContent = result.title;
                            const points = document.createElement('span');
                            points.textContent = `+${result.awarded_points}`;
                            card.append(title, points);
                            this.summaryListEl.appendChild(card);
                        });
                    }
                    if (this.summaryTotalEl) {
                        this.summaryTotalEl.textContent = this.totalEarnedPoints;
                    }
                    if (this.summaryAccountEl) {
                        this.summaryAccountEl.textContent = this.childPoints;
                    }
                }

                finalizeRoutine() {
                    const payload = new FormData();
                    payload.append('action', 'complete_routine_flow');
                    payload.append('routine_id', this.routine.id);
                    const metrics = this.taskResults.map(result => ({
                        id: result.id,
                        actual_seconds: result.actual_seconds,
                        scheduled_seconds: result.scheduled_seconds
                    }));
                    payload.append('task_metrics', JSON.stringify(metrics));
                    fetch('routine.php', { method: 'POST', body: payload })
                        .then(response => response.json())
                        .then(data => {
                            if (data && data.status === 'duplicate') {
                                if (this.summaryBonusEl) {
                                    this.summaryBonusEl.textContent = data.message || '';
                                }
                                return;
                            }
                            if (Array.isArray(data.task_results)) {
                                this.taskResults = data.task_results;
                                this.renderSummary(this.taskResults);
                            }
                            if (typeof data.new_total_points === 'number') {
                                this.childPoints = data.new_total_points;
                                page.childPoints = this.childPoints;
                                if (this.summaryAccountEl) {
                                    this.summaryAccountEl.textContent = this.childPoints;
                                }
                            }
                            if (typeof data.task_points_awarded === 'number' && this.summaryTotalEl) {
                                this.summaryTotalEl.textContent = data.task_points_awarded;
                            }
                            if (this.summaryBonusEl) {
                                const bonus = typeof data.bonus_points_awarded === 'number' ? data.bonus_points_awarded : 0;
                                if (bonus > 0) {
                                    this.summaryBonusEl.textContent = `Bonus awarded: +${bonus}`;
                                } else if (this.routine.bonus_points > 0) {
                                    this.summaryBonusEl.textContent = 'Bonus locked: all tasks must be on time.';
                                } else {
                                    this.summaryBonusEl.textContent = '';
                                }
                            }
                        })
                        .catch(() => {
                            if (this.summaryBonusEl) {
                                this.summaryBonusEl.textContent = 'Could not update totals—check your connection.';
                            }
                        })
                        .finally(() => {
                            this.sendOvertimeLogs();
                        });
                }

                showScene(name) {
                    this.sceneMap.forEach((scene, key) => {
                        if (key === name) {
                            scene.classList.add('active');
                        } else {
                            scene.classList.remove('active');
                        }
                    });
                    if (name === 'summary' && this.summaryAccountEl) {
                        this.summaryAccountEl.textContent = this.childPoints;
                    }
                }

                updateTaskHeader() {
                    if (this.flowTitleEl) {
                        this.flowTitleEl.textContent = this.currentTask ? this.currentTask.title : 'Routine Task';
                    }
                }

                updateNextLabel() {
                    if (!this.nextLabelEl) return;
                    const nextTask = this.tasks[this.currentIndex + 1];
                    this.nextLabelEl.textContent = nextTask ? nextTask.title : 'All done!';
                }

                resetRoutineStatuses() {
                    const payload = new FormData();
                    payload.append('action', 'reset_routine_tasks');
                    payload.append('routine_id', this.routine.id);
                    fetch('routine.php', { method: 'POST', body: payload }).catch(() => {});
                }

                updateTaskStatus(taskId, status) {
                    const payload = new FormData();
                    payload.append('action', 'set_routine_task_status');
                    payload.append('routine_id', this.routine.id);
                    payload.append('routine_task_id', taskId);
                    payload.append('status', status);
                    fetch('routine.php', { method: 'POST', body: payload }).catch(() => {});
                }

                markTaskCompleted(taskId) {
                    const item = this.card.querySelector(`li[data-routine-task-id="${taskId}"]`);
                    if (item) {
                        item.classList.add('task-completed');
                        const checkbox = item.querySelector('input[type="checkbox"]');
                        if (checkbox) checkbox.checked = true;
                        const pill = item.querySelector('.status-pill');
                        if (pill) {
                            pill.textContent = 'completed';
                            pill.classList.add('completed');
                            pill.classList.remove('pending');
                        }
                    }
                }

                markTaskPending(taskId) {
                    const item = this.card.querySelector(`li[data-routine-task-id="${taskId}"]`);
                    if (item) {
                        item.classList.remove('task-completed');
                        const checkbox = item.querySelector('input[type="checkbox"]');
                        if (checkbox) checkbox.checked = false;
                        const pill = item.querySelector('.status-pill');
                        if (pill) {
                            pill.textContent = 'pending';
                            pill.classList.remove('completed');
                            pill.classList.add('pending');
                        }
                    }
                }

                sendOvertimeLogs() {
                    if (!this.overtimeBuffer.length) {
                        return;
                    }
                    const payload = new FormData();
                    payload.append('action', 'log_overtime');
                    payload.append('overtime_payload', JSON.stringify(this.overtimeBuffer));
                    fetch('routine.php', { method: 'POST', body: payload }).catch(() => {});
                    this.overtimeBuffer = [];
                }
            }

            document.querySelectorAll('[data-role="toggle-card"]').forEach(button => {
                const content = button.parentElement.querySelector('[data-role="collapsible"]');
                if (!content) return;
                button.addEventListener('click', () => {
                    content.classList.toggle('active');
                });
            });

            document.querySelectorAll('.routine-builder').forEach(container => {
                const id = container.dataset.builderId || '';
                let initial = [];
                if (id === 'create') {
                    initial = Array.isArray(page.createInitial) ? page.createInitial : [];
                } else if (id.startsWith('edit-')) {
                    const key = id.replace('edit-', '');
                    initial = (page.editInitial && page.editInitial[key]) ? page.editInitial[key] : [];
                }
                new RoutineBuilder(container, initial);
            });

            (Array.isArray(page.routines) ? page.routines : []).forEach(routine => {
                const card = document.querySelector(`.routine-card[data-routine-id="${routine.id}"]`);
                if (!card) return;
                if (card.classList.contains('child-view')) {
                    new RoutinePlayer(card, routine, page.preferences, page.subTimerLabels);
                }
            });
        })();
    