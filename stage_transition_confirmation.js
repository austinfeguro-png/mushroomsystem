(function () {
    const stageLabels = {
        colonization: 'Colonization',
        pinning: 'Pinning Initiation',
        fruiting: 'Fruiting'
    };

    window.confirmStageTransition = function (currentStage, nextStage) {
        if (!currentStage || !nextStage || currentStage === nextStage) return true;

        const currentName = stageLabels[currentStage] || currentStage;
        const nextName = stageLabels[nextStage] || nextStage;
        const message = [
            'Confirm stage transition: ' + currentName + ' → ' + nextName + '?',
            '',
            'Inspect the substrate bags before confirming. This change resets the stage timer and applies the selected stage thresholds immediately.',
            'If the current stage duration has not elapsed, the transition will be recorded as early.'
        ].join('\n');

        return window.confirm(message);
    };
})();
